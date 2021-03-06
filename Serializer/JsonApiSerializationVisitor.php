<?php

/*
 * This file is part of the Mango package.
 *
 * (c) Steffen Brem <steffenbrem@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mango\Bundle\JsonApiBundle\Serializer;

use JMS\Serializer\Accessor\AccessorStrategyInterface;
use JMS\Serializer\Context;
use JMS\Serializer\JsonSerializationVisitor;
use JMS\Serializer\Metadata\ClassMetadata;
use JMS\Serializer\Metadata\PropertyMetadata;
use JMS\Serializer\Naming\PropertyNamingStrategyInterface;
use Mango\Bundle\JsonApiBundle\Configuration\Metadata\ClassMetadata as JsonApiClassMetadata;
use Mango\Bundle\JsonApiBundle\EventListener\Serializer\JsonEventSubscriber;
use Metadata\MetadataFactoryInterface;

/**
 * @author Steffen Brem <steffenbrem@gmail.com>
 */
class JsonApiSerializationVisitor extends JsonSerializationVisitor
{
    /**
     * @var MetadataFactoryInterface
     */
    protected $metadataFactory;

    /**
     * @var bool
     */
    protected $showVersionInfo;

    /**
     * @var integer
     */
    protected $includeMaxDepth = false;

    private $isJsonApiDocument = false;

    private $includedResources = [];

    private $includeTypes = [];

    /**
     * JsonApiSerializationVisitor constructor.
     * @param PropertyNamingStrategyInterface $propertyNamingStrategy
     * @param AccessorStrategyInterface $accessorStrategy
     * @param MetadataFactoryInterface $metadataFactory
     * @param bool $showVersionInfo
     * @param int $includeMaxDepth
     */
    public function __construct(
        PropertyNamingStrategyInterface $propertyNamingStrategy,
        AccessorStrategyInterface $accessorStrategy,
        MetadataFactoryInterface $metadataFactory,
        $showVersionInfo,
        $includeMaxDepth = null
    ) {
        parent::__construct($propertyNamingStrategy, $accessorStrategy);

        $this->metadataFactory = $metadataFactory;
        $this->showVersionInfo = $showVersionInfo;
        $this->includeMaxDepth = $includeMaxDepth;
    }

    /**
     * @return bool
     */
    public function isJsonApiDocument()
    {
        return $this->isJsonApiDocument;
    }

    /**
     * @param mixed $root
     *
     * @return array
     */
    public function prepare($root)
    {
        $this->includedResources = [];
        if (is_array($root) && array_key_exists('data', $root)) {
            $data = $root['data'];
        } else {
            $data = $root;
        }

        $this->isJsonApiDocument = $this->validateJsonApiDocument($data);

        if ($this->isJsonApiDocument) {
            $meta = null;
            if (is_array($root) && isset($root['meta']) && is_array($root['meta'])) {
                $meta = $root['meta'];
            }

            return $this->buildJsonApiRoot($data, $meta);
        }

        return $root;
    }

    protected function buildJsonApiRoot($data, array $meta = null)
    {
        $root = array(
            'data' => $data,
        );

        if ($meta) {
            $root['meta'] = $meta;
        }

        return $root;
    }

    /**
     * it is a JSON-API document if:
     *  - it is an object and is a JSON-API resource
     *  - it is an array containing objects which are JSON-API resources
     *  - it is empty (we cannot identify it)
     *
     * @param mixed $data
     *
     * @return bool
     */
    protected function validateJsonApiDocument($data)
    {
        if (is_array($data) && count($data) > 0 && !$this->hasResource($data)) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getResult()
    {
        if (false === $this->isJsonApiDocument) {
            return parent::getResult();
        }

        $root = $this->getRoot();

        // TODO: Error handling
        if (isset($root['data']) && array_key_exists('errors', $root['data'])) {
            return parent::getResult();
        }

        if ($root) {
            $data = array();
            $meta = array();
            $links = array();

            if (isset($root['data'])) {
                $data = $root['data'];
            }

            if (isset($root['meta'])) {
                $meta = $root['meta'];
            }

            if (isset($root['links'])) {
                $links = $root['links'];
            }

            // start building new root array
            $root = array();

            if ($this->showVersionInfo) {
                $root['jsonapi'] = array(
                    'version' => '1.0',
                );
            }

            if ($meta) {
                $root['meta'] = $meta;
            }

            if ($links) {
                $root['links'] = $links;
            }

            $root['data'] = $data;

            if ($this->includedResources) {
                $root['included'] = $this->includedResources;
            }

            $this->setRoot($root);
        }

        return parent::getResult();
    }

    public function visitProperty(PropertyMetadata $metadata, $data, Context $context)
    {
        /** @var JsonApiClassMetadata $jsonApiMetadata */
        $jsonApiMetadata = $this->metadataFactory->getMetadataForClass($metadata->class);

        if (!$jsonApiMetadata || !$jsonApiMetadata->getResource() || !$jsonApiMetadata->hasRelationship($metadata->name)) {
            return parent::visitProperty($metadata, $data, $context);
        }

        $relationship = $jsonApiMetadata->getRelationship($metadata->name);
        $includeMaxDepth = $relationship->getIncludeDepth() ?: $this->includeMaxDepth;

        $propertyData = $metadata->getValue($data);

        $contextDepthIgnoringCollections = count($context->getCurrentPath());

        if (null === $propertyData) {
            $data = null;
        } elseif (is_array($propertyData) || $propertyData instanceof \Traversable) {
            $data = [];

            foreach ($propertyData as $v) {
                $propertyMetadata = $this->metadataFactory->getMetadataForClass(get_class($v));
                $id = (string) $propertyMetadata->getIdValue($v);
                $resourceType = $propertyMetadata->getResource()->getType();

                $data[] = [
                    'type' => $resourceType,
                    'id' => $id
                ];
                if ($relationship->isIncludedByDefault() || in_array($resourceType, $this->includeTypes)) {
                    if (null === $includeMaxDepth || $contextDepthIgnoringCollections <= $includeMaxDepth) {
                        if (!$this->isRelationshipIncluded($propertyMetadata, $id)) {
                            $this->addIncluded($propertyMetadata, $context->accept($v));
                        }
                    }
                }
            }
        } else {
            $propertyMetadata = $this->metadataFactory->getMetadataForClass(get_class($propertyData));
            $id = (string) $propertyMetadata->getIdValue($propertyData);
            $resourceType = $propertyMetadata->getResource()->getType();

            $data = [
                'type' => $resourceType,
                'id' => $id
            ];
            if ($relationship->isIncludedByDefault() || in_array($resourceType, $this->includeTypes)) {
                if (null === $includeMaxDepth || $contextDepthIgnoringCollections <= $includeMaxDepth) {
                    if (!$this->isRelationshipIncluded($propertyMetadata, $id)) {
                        $this->addIncluded($propertyMetadata, $context->accept($propertyData));
                    }
                }
            }
        }

        $key = $this->namingStrategy->translateName($metadata);

        $this->data[$key] = $data;
    }



    /**
     * {@inheritdoc}
     */
    public function endVisitingObject(ClassMetadata $metadata, $data, array $type, Context $context)
    {
        /** @var JsonApiClassMetadata $jsonApiMetadata */
        $jsonApiMetadata = $this->metadataFactory->getMetadataForClass(get_class($data));

        $rs = parent::endVisitingObject($metadata, $data, $type, $context);

        if ($rs instanceof \ArrayObject) {
            $rs = [];
            $this->setRoot($rs);

            return $rs;
        }

        if (null === $jsonApiMetadata || null === $jsonApiMetadata->getResource()) {
            return $rs;
        }

        $result = array();

        $result['type'] = $jsonApiMetadata->getResource()->getType();

        $idField = $jsonApiMetadata->getIdField();

        $result['id'] = isset($rs[$idField]) ? (string) $rs[$idField] : null;

        $result['attributes'] = array_filter($rs, function ($key) use ($idField, $jsonApiMetadata) {
            switch ($key) {
                case $idField:
                case 'relationships':
                case 'links':
                case JsonEventSubscriber::EXTRA_DATA_KEY:
                    return false;
            }

            if ($jsonApiMetadata->hasRelationship($key)) {
                return false;
            }

            return true;
        }, ARRAY_FILTER_USE_KEY);

        if (isset($rs['relationships'])) {
            $result['relationships'] = $rs['relationships'];
        }

        if (isset($rs['links'])) {
            $result['links'] = $rs['links'];
        }

        return $result;
    }

    /**
     * @param JsonApiClassMetadata $jsonApiMetadata
     * @param array $relationshipData
     */
    private function addIncluded(JsonApiClassMetadata $jsonApiMetadata, $relationshipData)
    {
        if (!is_array($relationshipData) || !isset($relationshipData['id'])) {
            return;
        }

        $existingRelationship = array_filter(
            $this->includedResources,
            function ($relationship) use ($relationshipData) {
                return
                    $relationship['id'] === $relationshipData['id'] &&
                    $relationship['type'] === $relationshipData['type']
                ;
            }
        );

        if (!empty($existingRelationship)) {
            return;
        }

        $this->includedResources[] = $relationshipData;
    }

    /**
     * @param $items
     *
     * @return bool
     */
    protected function hasResource($items)
    {
        foreach ($items as $item) {
            return $this->isResource($item);
        }

        return false;
    }

    /**
     * Check if the given variable is a valid JSON-API resource.
     *
     * @param $data
     *
     * @return bool
     */
    protected function isResource($data)
    {
        if (is_object($data)) {
            /** @var JsonApiClassMetadata $metadata */
            if ($metadata = $this->metadataFactory->getMetadataForClass(get_class($data))) {
                if ($metadata->getResource()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param JsonApiClassMetadata $jsonApiMetadata
     * @param string $id
     * @return boolean
     */
    private function isRelationshipIncluded(JsonApiClassMetadata $jsonApiMetadata, $id)
    {
        // filter out dupes
        foreach ($this->includedResources as $included) {
            if (
                $id === $included['id']
                && $jsonApiMetadata->getResource()->getType() === $included['type']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Array $includeTypes
     */
    public function setIncludeTypes($includeTypes) {
        $this->includeTypes = $includeTypes;
    }
}
