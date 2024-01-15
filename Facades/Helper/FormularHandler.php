<?php
namespace axenox\ETL\Facades\Helper;

use Flow\JSONPath\JSONPath;

class FormularHandler {
	private $formularBaseJson;
	private $dataJson;
	private $currentJsonPath = '$';
	
	
	/**
	 *
	 * @param string $formularJson
	 * @param string $dataJson
	 */
	public function __construct(string $formularJson, string $dataJson)
	{
		$this->formularBaseJson = json_decode($formularJson, true);
		$this->dataJson = json_decode($dataJson, true);
	}
	
	public function createJson() : string
	{
		$formular = [];
		$this->fillFormularJson($this->formularBaseJson, $formular, $this->currentJsonPath);
		$html = $this->writeHtml($formular);
		return $html;
	}
	
	protected function fillFormularJson(array $source, array &$json, string &$currentJsonPath)
	{
		foreach ($source as $key => $jsonPart){
			if (is_numeric($key)){				
				$currentJsonPath = $currentJsonPath . '.[' . $key . ']';
				$this->fillFormularJson($source[$key], $json, $currentJsonPath);
			}
			
			switch(true){
				case $key === 'title' && array_key_exists('pages', $source):
					$currentJsonPath = $currentJsonPath . '.pages';
					$json['Titel'] = ['value' => $jsonPart, 'type' => 'text'];
					$this->fillFormularJson($source['pages'], $json, $currentJsonPath);
					break;
				case $key === 'name'
					&& array_key_exists('type', $source) === false:
					$json['Formular'] = ['value' => $jsonPart, 'type' => 'text'];
					break;
				case $key === 'name' && strpos($jsonPart, 'Frage') !== false:
					$this->buildQuestion($json, $source, $currentJsonPath);
					break;
				case $key === 'elements':
					$currentJsonPath = $currentJsonPath . '.elements';
					$this->fillFormularJson($jsonPart, $json, $currentJsonPath);
					break;
			}
		}
	}
	
	protected function buildQuestion(mixed &$json,array $question, string $jsonPath)
	{
		$dataValue = $this->dataJson[$question['name']];		
		if ($dataValue === null){
			return;
		}
		
		$value = null;
		switch ($question['type']){
			case 'boolean':
			case 'text':
				$value = $dataValue;
				break;
			case 'dropdown':
				$dropdown =  $question['choices'];
				foreach ($dropdown as $item){
					if ($item['value'] === $dataValue){
						$value = $item['text'];
						break;
					}
				}
				break;
		}
		
		$json[$question['title']] = ['value' => $value, 'type' => $question['type']];
	}
	
	private function writeHtml(array $json)
	{
		$htmlElements = [];
		foreach ($json as $key => $entry){
			$divString = '<div class=\'form-control\'> <label>'. $key .'</label>';
			switch ($entry['type']) {
				case 'boolean':
					$checked = $entry['value'] ? 'checked=\'checked\'' : '';
					$divString = $divString. '<input type=\'checkbox\''. $checked . '>'. ($entry['value'] === true ? 'Ja' : 'Nein') .'</input></div>';
					break;
				case 'text':
					$divString = $divString. '<input type=\'text\' value=\''. $entry['value'] .'\'/> </div>';
					break;
				case 'dropdown':
					$divString = $divString. '<select> <option>'.$entry['value'].'</option> </select> </div>';
					break;
			}
			
			array_push($htmlElements, stripcslashes($divString));
		}
		$htmlElements = implode('', $htmlElements);
		return <<<HTML
		<!doctypehtml><html lang=en><head>{$this->getFormularStyleHtml()}<body><form id="form">{$htmlElements}</form></body>
		HTML;
	}
		
	private function getFormularStyleHtml(){
		return <<<HTML
	<style>body{background-color:#ebf5fe;font-family:Verdana;text-align:center}form{background-color:#fff;max-width:500px;margin:50px auto;padding:30px 20px;box-shadow:2px 5px 10px rgba(0,0,0,.5)}.form-control{text-align:left;margin-bottom:25px}.form-control label{display:block;margin-bottom:10px}.form-control input,.form-control select,.form-control textarea{border:1px solid #777;border-radius:2px;font-family:inherit;padding:10px;display:block;width:95%}.form-control input[type=checkbox],.form-control input[type=radio]{display:inline-block;width:auto}button{background-color:#05c46b;border:1px solid #777;border-radius:2px;font-family:inherit;font-size:21px;display:block;width:100%;margin-top:50px;margin-bottom:20px}</style>
	HTML;
	}
}