<?php
namespace axenox\ETL\Facades;

use exface\Core\Facades\AbstractHttpFacade\Middleware\JsonBodyParser;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use axenox\ETL\Actions\RunETLFlow;
use axenox\ETL\DataTypes\WebRequestStatusDataType;
use exface\Core\CommonLogic\Selectors\ActionSelector;
use exface\Core\CommonLogic\Tasks\HttpTask;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\InternalError;
use exface\Core\Exceptions\Facades\FacadeRoutingError;
use exface\Core\Exceptions\DataTypes\JsonSchemaValidationError;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use exface\Core\Facades\AbstractHttpFacade\Middleware\RouteConfigLoader;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use axenox\ETL\Interfaces\OpenApiFacadeInterface;
use axenox\ETL\Facades\Middleware\OpenApiValidationMiddleware;
use axenox\ETL\Facades\Middleware\OpenApiMiddleware;
use axenox\ETL\Facades\Middleware\SwaggerUiMiddleware;
use Flow\JSONPath\JSONPath;

/**
 * 
 * 
 * @author Andrej Kabachnik
 * 
 */
class DataFlowFacade extends AbstractHttpFacade implements OpenApiFacadeInterface
{

	const REQUEST_ATTRIBUTE_NAME_ROUTE = 'route';

	private $openApiCache = [];

	/**
	 * {@inheritDoc}
	 * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::createResponse()
	 */
	protected function createResponse(
		ServerRequestInterface $request): ResponseInterface
	{
		$headers = $this->buildHeadersCommon();

		try {
			$path = $request->getUri()->getPath();
			$path = StringDataType::substringAfter($path, $this->getUrlRouteDefault() . '/', '');
			$routePath = rtrim(strstr($path, '/'), '/');
			$routeModel = $this->getRouteData($request);
			$requestLogData = $this->logRequestReceived($request);

			// process flow
			$routeUID = $routeModel['UID'];
			$flowAlias = $routeModel['flow__alias'];
			$flowRunUID = RunETLFlow::generateFlowRunUid();
			$requestLogData = $this->logRequestProcessing($requestLogData, $routeUID, $flowRunUID); // flow data update
			$flowResult = $this->runFlow($flowAlias, $request, $requestLogData); // flow data update
			$flowOutput = $flowResult->getMessage();
	
			// get changes by flow
			$this->reloadRequestData($requestLogData);
				
			if ($requestLogData->countRows() == 1) {
				$body = $this->createRequestResponseBody($requestLogData, $request, $headers, $routeModel, $routePath);
				$response = new Response(200, $headers, $body);
			}
			
		} catch (\Throwable $e) {
			if ($requestLogData === null) {
				$requestLogData = $this->logRequestReceived($request);
			}
			
			// get changes by flow
			$this->reloadRequestData($requestLogData);
			if (!$e instanceof ExceptionInterface) {
				$e = new InternalError($e->getMessage(), null, $e);
			}

			$response = $this->createResponseFromError($e, $request, $requestLogData);
			$requestLogData = $this->logRequestFailed($requestLogData, $e, $response);
			return $response;
		}

		if ($response === null) {
			$response = new Response(200, $headers, 'Dataflow successfull.');
		}

		$requestLogData = $this->logRequestDone($requestLogData, $flowOutput, $response);
		return $response;
	}

	/**
	 * {@inheritDoc}
	 * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getUrlRouteDefault()
	 */
	public function getUrlRouteDefault(): string
	{
		return 'api/dataflow';
	}

	/**
	 * @param string $flowAlias
	 * @param ServerRequestInterface $request
	 * @param DataSheetInterface $requestLogData
	 * @return ResultInterface
	 */
	protected function runFlow(
		string $flowAlias,
		ServerRequestInterface $request,
		DataSheetInterface $requestLogData): ResultInterface
	{
		$task = new HttpTask($this->getWorkbench(), $this, $request);
		$task->setInputData($requestLogData);

		$actionSelector = new ActionSelector($this->getWorkbench(), RunETLFlow::class);
		/* @var $action \axenox\ETL\Actions\RunETLFlow */
		$action = ActionFactory::createFromPrototype($actionSelector, $this->getApp());
		$action->setMetaObject($requestLogData->getMetaObject());
		$action->setFlowAlias($flowAlias);
		$action->setInputFlowRunUid('flow_run');
		$result = $action->handle($task);
		return $result;
	}

	/**
	 * @param string $routeUID
	 * @param string $flowRunUID
	 * @param ServerRequestInterface $request
	 * @return DataSheetInterface
	 */
	protected function logRequestReceived(
		ServerRequestInterface $request): DataSheetInterface
	{
		$ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.webservice_request');
		$ds->addRow([
			'status' => WebRequestStatusDataType::RECEIVED, 
			'url' => $request->getUri()->__toString(), 
			'url_path' => StringDataType::substringAfter(
				$request->getUri()->getPath(), 
				$this->getUrlRouteDefault() . '/', 
				$request->getUri()->getPath()),
			'http_method' => $request->getMethod(), 
			'http_headers' => JsonDataType::encodeJson($request->getHeaders()), 
			'http_body' => $request->getBody()->__toString(), 
			'http_content_type' => implode(';', $request->getHeader('Content-Type'))]);
		
		$ds->dataCreate(false);
		return $ds;
	}

	/**
	 * @param string $routeUID
	 * @param string $flowRunUID
	 * @param ServerRequestInterface $request
	 * @return DataSheetInterface
	 */
	protected function logRequestProcessing(
		DataSheetInterface $requestLogData,
		string $routeUID,
		string $flowRunUID): DataSheetInterface
	{
		$ds = $requestLogData->extractSystemColumns();
		$ds->setCellValue('route', 0, $routeUID);
		$ds->setCellValue('status', 0, WebRequestStatusDataType::PROCESSING);
		$ds->setCellValue('flow_run', 0, $flowRunUID);
		$ds->dataUpdate(false);
		return $ds;
	}

	/**
	 * @param string $requestLogUID
	 * @param ExceptionInterface $e
	 * @return DataSheetInterface
	 */
	protected function logRequestFailed(
		DataSheetInterface $requestLogData,
		\Throwable $e,
		ResponseInterface $response = null): DataSheetInterface
	{
		if (!($e instanceof ExceptionInterface)) {
			$e = new InternalError($e->getMessage(), null, $e);
		}
		$this->getWorkbench()
			->getLogger()
			->logException($e);
		$ds = $requestLogData->extractSystemColumns();
		$ds->setCellValue('status', 0, WebRequestStatusDataType::ERROR);
		$ds->setCellValue('error_message', 0, $e->getMessage());
		$ds->setCellValue('error_logid', 0, $e->getId());
		$ds->setCellValue('http_response_code', 0, $response !== null ? $response->getStatusCode() : $e->getStatusCode());
		$ds->setCellValue('response_header', 0, json_encode($response->getHeaders()));
		$ds->setCellValue('response_body', 0, $response->getBody()
			->__toString());
		$ds->dataUpdate(false);
		return $ds;
	}
	
	/**
	 * @param DataSheetInterface $requestLogData
	 * @param ServerRequestInterface $request
	 * @param array $headers
	 * @param array $routeModel
	 * @param array $routePath
	 */
	private function createRequestResponseBody(
		DataSheetInterface $requestLogData,
		ServerRequestInterface $request,
		array &$headers,
		array $routeModel,
		string $routePath) : ?string
	{
		$flowResponse = json_decode($requestLogData->getRow()['response_body'], true);
		
		// load response model from swagger
		$methodType = strtolower($request->getMethod());
		$jsonPath = $routeModel['type__default_response_path'];
		if ($jsonPath !== null) {
			$responseModel = $this->readDataFromSwaggerJson(
				$routePath,
				$methodType,
				$jsonPath,
				$routeModel['swagger_json']);
		}

		if ($responseModel === null && empty($responseModel)) {
            return null;
		}

        $headers['Content-Type'] = 'application/json';
		// merge flow response into empty model
        if ($flowResponse !== null && empty($flowResponse) === false){
            $body = array_merge($responseModel, $flowResponse);
        } else {
            $body = $responseModel;
        }

		return json_encode($body);
	}

	/**
	 * @param string $requestLogUID
	 * @param string $output
	 * @return DataSheetInterface
	 */
	protected function logRequestDone(
		DataSheetInterface $requestLogData,
		string $output,
		ResponseInterface $response): DataSheetInterface
	{
		$ds = $requestLogData->extractSystemColumns();
		$ds->setCellValue('status', 0, WebRequestStatusDataType::DONE);
		$ds->setCellValue('result_text', 0, $output);
		$ds->setCellValue('http_response_code', 0, $response->getStatusCode());
		$ds->setCellValue('response_header', 0, json_encode($response->getHeaders()));
		$ds->setCellValue('response_body', 0, $response->getBody()
			->__toString());
		$ds->dataUpdate(false);
		return $ds;
	}

	/**
	 * @param string $route
	 * @throws FacadeRoutingError
	 * @return string[]
	 */
	protected function getRouteData(
		ServerRequestInterface $request): ?array
	{
		return $request->getAttribute(self::REQUEST_ATTRIBUTE_NAME_ROUTE, null);
	}

	/**
	 * {@inheritDoc}
	 * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getMiddleware()
	 */
	protected function getMiddleware(): array
	{
		$ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.ETL.webservice');
		$ds->getColumns()->addMultiple(
			['UID', 'flow', 'flow__alias', 'local_url', 'type__schema_json', 'type__default_response_path', 'swagger_json', 'config_uxon']);
		$ds->dataRead();
		
		$middleware = parent::getMiddleware();
		array_push(
			$middleware, 
			new RouteConfigLoader($this, $ds, 'local_url', 'config_uxon', self::REQUEST_ATTRIBUTE_NAME_ROUTE),
			new OpenApiValidationMiddleware($this, ['/.*swaggerui$/', '/.*openapi\\.json$/']),
			new OpenApiMiddleware($this, $this->buildHeadersCommon(), '/.*openapi\\.json$/'),
			new SwaggerUiMiddleware($this, $this->buildHeadersCommon(), '/.*swaggerui$/', 'openapi.json'));
		
		return $middleware;
	}

	/**
	 * {@inheritDoc}
	 * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::createResponseFromError()
	 */
	protected function createResponseFromError(\Throwable $exception, ServerRequestInterface $request = null): ResponseInterface
	{
		$code = $exception->getStatusCode();
		$headers = $this->buildHeadersCommon();

		if ($this->getWorkbench()
			->getSecurity()
			->getAuthenticatedToken()
			->isAnonymous()) {
			return new Response($code, $headers);
		}
		
		if ($exception instanceof JsonSchemaValidationError) {			
			$headers['Content-Type'] = 'application/json';
            $context = $exception->getContext();
			$errors = [$context => []];
			foreach ($exception->getErrors() as $error) {
				if (is_array($error)){
					$errors[$context][] = ['source' => $error['property'], 'message' => $error['message']];
				} else {
					$errors[$context] = $error;
				}
			}
			
			return new Response(400, $headers, json_encode($errors));
		}

		$headers['Content-Type'] = 'application/json';
		$errorData = json_encode(['Error' => [
			'Message' => $exception->getMessage(), 
			'Log-Id' => $exception->getId()]
		]);

		return new Response($code, $headers, $errorData);
	}

	/**
	 * @param DataSheetInterface $requestLogData
	 */
	private function reloadRequestData(DataSheetInterface $requestLogData)
	{
		$requestLogData->getFilters()->addConditionFromColumnValues($requestLogData->getUidColumn());
		$requestLogData->getColumns()->addFromExpression('response_body');
		$requestLogData->dataRead();
	}

	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::buildHeadersCommon()
	 */
	protected function buildHeadersCommon(): array
	{
		$facadeHeaders = array_filter($this->getConfig()
			->getOption('FACADE.HEADERS.COMMON')
			->toArray());
		$commonHeaders = parent::buildHeadersCommon();
		return array_merge($commonHeaders, $facadeHeaders);
	}
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \axenox\ETL\Interfaces\OpenApiFacadeInterface::getOpenApiJson()
	 */
	public function getOpenApiJson(ServerRequestInterface $request): ?array
	{
		$path = $request->getUri()->getPath();
		if (array_key_exists($path, $this->openApiCache)) {
			return $this->swaggerCache[$path];
		}
		$routeData = $request->getAttribute(self::REQUEST_ATTRIBUTE_NAME_ROUTE);
		if (empty($routeData)) {
			throw new FacadeRoutingError('No route data found in request!');
		}
		$json = $routeData['swagger_json'];
		if ($json === null || $json === '') {
			return null;
		}
		
		JsonDataType::validateJsonSchema($json, $routeData['type__schema_json']);		
		$jsonArray = json_decode($json, true);
		$jsonArray = $this->addServerPaths($path, $jsonArray);
		$this->openApiCache[$path] = $jsonArray;
		return $jsonArray;
	}
	
	/**
	 * @param string $path
	 * @param array $swaggerArray
	 * @return array
	 */
	private function addServerPaths(string $path, array $swaggerArray): array
	{
		$basePath = $this->getUrlRouteDefault();
		$routePath = StringDataType::substringAfter($path, $basePath, $path);
		$webserviceBase = StringDataType::substringBefore($routePath, '/', '', true, true) . '/';
		$basePath .= '/' . ltrim($webserviceBase, "/");
		foreach ($this->getWorkbench()
			->getConfig()
			->getOption('SERVER.BASE_URLS') as $baseUrl) {
				$swaggerArray['servers'][] = ['url' => $baseUrl . $basePath];
		}
			
		return $swaggerArray;
	}	
	
	/**
	 * Selects data from a swaggerJson with the given json path.
	 * Route path and method type are used to replace placeholders within the path.
	 *
	 * @param string $routePath
	 * @param string $methodType
	 * @param string $jsonPath
	 * @param string $swaggerJson
	 * @return array|null
	 */
	public function readDataFromSwaggerJson(
		string $routePath,
		string $methodType,
		string $jsonPath,
		string $swaggerJson): ?array
    {
			require_once '..' . DIRECTORY_SEPARATOR
			. '..' . DIRECTORY_SEPARATOR
			. 'axenox' . DIRECTORY_SEPARATOR
			. 'etl' . DIRECTORY_SEPARATOR
			. 'Common' . DIRECTORY_SEPARATOR
			. 'JSONPath' . DIRECTORY_SEPARATOR
			. 'JSONPathLexer.php';
			
			$jsonPath = str_replace('[#routePath#]', $routePath, $jsonPath);
			$jsonPath = str_replace('[#methodType#]', $methodType, $jsonPath);
			$data = (new JSONPath(json_decode($swaggerJson, false)))->find($jsonPath)->getData()[0];
			return $data === null ? null : get_object_vars($data);
	}
}
