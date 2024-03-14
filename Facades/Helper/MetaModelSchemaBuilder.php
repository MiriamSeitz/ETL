<?php
namespace axenox\ETL\Facades\Helper;

use exface\Core\CommonLogic\DataSheets\DataColumn;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\BinaryDataType;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * A builder that creates a schema from a MetaObjectInterface.
 * 
 * @author miriam.seitz
 *
 */
class MetaModelSchemaBuilder
{
    private bool $onlyReturnProperties;

    private bool $forceSchema;

    private bool $loadExamples;

    private ?array $relationObjectsToLoad;

    /**
     * Create the builder and configure the creation of a json schema.
     *
     * @param array|null $relationObjects adds these attributes as references - they must be present in the schema json!
     * @param bool $onlyReturnProperties configure if the response will be wrapped in the attribute alias with namespace
     * @param bool $forceSchema configure if the schema will set all properties required and does not allow additional properties
     * @param bool $loadExamples configure if every property will get an example
     */
    public function __construct(
        ?array $relationObjects = null,
        bool   $onlyReturnProperties = false,
        bool   $forceSchema = false,
        bool   $loadExamples = true)
    {
        $this->onlyReturnProperties = $onlyReturnProperties;
        $this->forceSchema = $forceSchema;
        $this->loadExamples = $loadExamples;
        $this->relationObjectsToLoad = $relationObjects;
    }

    /**
     * Create a json schema for the given meta object. Uses all DataType classes supported by JsonSchema.
     *
     * @param MetaObjectInterface $metaObject
     * @param array $attributeAliasesToAdd
     */
    public function transformIntoJsonSchema(MetaObjectInterface $metaObject): array
    {
        return [];
        $objectName = $metaObject->getAliasWithNamespace();
        if ($this->onlyReturnProperties) {
            $jsonSchema = ['type' => 'object', 'properties' => []];
            $subArray = $jsonSchema[$objectName]['properties'];

        } else {
            $jsonSchema = [$objectName => ['type' => 'object', 'properties' => []]];
            $subArray = $jsonSchema['properties'];
        }

        if ($this->loadExamples) {
            $ds = DataSheetFactory::createFromObject($metaObject);

            $columns = [];
            foreach ($metaObject->getAttributes()->getAll() as $attr) {
                $columns[] = $attr->getAlias();
            }
            $ds->getColumns()->addMultiple($columns);

            foreach ($ds->getColumns() as $column){
                $amountOfNull[] = "CASE WHEN {$column->getName()} IS NOT NULL THEN 0 ELSE 1 END";
            }
            $amountOfNullValues = "(" . implode(" + ", $amountOfNull) . ") as AmountOfNullValues";
            $col = new DataColumn($amountOfNullValues, null, 'AmountOfNullValues');
            $ds->getColumns()->add($col);
            $ds->getSorters()->addFromString('AmountOfNullValues');
            $ds->dataRead(1);
        }

        $properties = [];
        foreach ($metaObject->getAttributes() as $attribute) {
            $properties[] = $attribute->getAlias();
            $dataType = $attribute->getDataType();
            switch (true) {
                case $attribute->isRelation():
                    $relatedObjectAlias = $attribute->getRelation()
                        ->getRightObject()
                        ->getAliasWithNamespace();
                    if (in_array($relatedObjectAlias, $this->relationObjectsToLoad)) {
                        $schema = ['$ref' => '#/components/schemas/Metamodel Informationen/properties/' . $relatedObjectAlias];
                        $subArray[$attribute->getAlias()] = $schema;
                        continue 2;
                    }
                case $dataType instanceof IntegerDataType:
                    $schema = ['type' => 'integer'];
                    break;
                case $dataType instanceof NumberDataType:
                    $schema = ['type' => 'number'];
                    break;
                case $dataType instanceof BooleanDataType:
                    $schema = ['type' => 'boolean'];
                    break;
                case $dataType instanceof ArrayDataType:
                    $schema = ['type' => 'array'];
                    break;
                case $dataType instanceof EnumDataTypeInterface:
                    $schema = ['type' => 'string', 'enum' => $dataType->getValues()];
                    break;
                case $dataType instanceof DateTimeDataType:
                    $schema = ['type' => 'string', 'format' => 'datetime'];
                    break;
                case $dataType instanceof DateDataType:
                    $schema = ['type' => 'string', 'format' => 'date'];
                    break;
                case $dataType instanceof BinaryDataType:
                    if ($dataType->getEncoding() == 'base64') {

                        $schema = ['type' => 'string', 'format' => 'byte'];
                    } else {
                        $schema = ['type' => 'string', 'format' => 'binary'];
                    }
                    break;
                case $dataType instanceof StringDataType:
                    $schema = ['type' => 'string'];
                    break;
                default:
                    throw new InvalidArgumentException('Datatype: ' . $dataType . ' not recognized.');
            }

            if ($attribute->getHint() !== $attribute->getName()) {
                $schema['description'] = $attribute->getHint();
            }

            if (($rowValue = $ds->getRow()[$attribute->getAlias()]) !== null){
                $schema['example'] = $rowValue;
            }

            $subArray[$attribute->getAlias()] = $schema;
        }

        if ($this->forceSchema) {
            $jsonSchema['additionalProperties'] = false;
            $jsonSchema['required'] = $properties;
        }

        return $jsonSchema;
    }
}