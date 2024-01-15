<?php
namespace axenox\ETL\Common\LogBooks;

use exface\Core\DataTypes\PhpClassDataType;
use exface\Core\CommonLogic\Debugger\LogBooks\DataLogBook;
use exface\Core\Interfaces\Facades\FacadeInterface;
use Psr\Http\Message\RequestInterface;

class FacadeLogbook extends DataLogBook
{
	private $facade = null;
	private $request = null;    

    /**
     * 
     * @param string $title
     * @param FacadeInterface $facade
     * @param RequestInterface $request
     */
    public function __construct(string $title, FacadeInterface $facade, RequestInterface $request = null)
    {
        parent::__construct($title);
        $this->facade = $facade;
        $this->request = $request;
        $this->addSection('Facade ' . $facade->getAliasWithNamespace());
        $this->addLine('Request: `' . $request->getUri() . '`');
    }
    
    /**
     * 
     * @return FacadeInterface
     */
    public function getFacade() : FacadeInterface
    {
        return $this->facade;
    }
    
    /**
     * 
     * @return RequestInterface
     */
    public function getRequest() : RequestInterface
    {
        return $this->event;
    }
}