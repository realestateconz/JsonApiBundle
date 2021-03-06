<?php

/*
 * This file is part of the Mango package.
 *
 * (c) Steffen Brem <steffenbrem@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mango\Bundle\JsonApiBundle\EventListener\Serializer;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Inflector\Inflector;
use Doctrine\Common\Util\ClassUtils;
use JMS\Serializer\Context;
use JMS\Serializer\DeserializationContext;
use JMS\Serializer\EventDispatcher\Events;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\EventDispatcher\ObjectEvent;
use JMS\Serializer\EventDispatcher\PreDeserializeEvent;
use JMS\Serializer\Metadata\ClassMetadata as JmsClassMetadata;
use JMS\Serializer\Naming\PropertyNamingStrategyInterface;
use JMS\Serializer\SerializationContext;
use Mango\Bundle\JsonApiBundle\Configuration\Metadata\ClassMetadata;
use Mango\Bundle\JsonApiBundle\Configuration\Relationship;
use Mango\Bundle\JsonApiBundle\Representation\OffsetPaginatedRepresentation;
use Mango\Bundle\JsonApiBundle\Serializer\JsonApiResource;
use Mango\Bundle\JsonApiBundle\Serializer\JsonApiSerializationVisitor;
use Metadata\MetadataFactoryInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Traversable;

/**
 * @author Steffen Brem <steffenbrem@gmail.com>
 */
class JsonEventSubscriber implements EventSubscriberInterface
{
    const EXTRA_DATA_KEY = '__DATA__';
    const LINK_SELF = 'self';
    const LINK_RELATED = 'related';

    /**
     * @var MetadataFactoryInterface
     */
    protected $hateoasMetadataFactory;

    /**
     * @var MetadataFactoryInterface
     */
    protected $jmsMetadataFactory;

    /**
     * @var PropertyNamingStrategyInterface
     */
    protected $namingStrategy;

    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var RouterInterface
     */
    protected $router;

    /**
     * @var string
     */
    protected $currentPath;

    protected $includedData;

    /**
     * @param MetadataFactoryInterface        $hateoasMetadataFactory
     * @param MetadataFactoryInterface        $jmsMetadataFactory
     * @param PropertyNamingStrategyInterface $namingStrategy
     * @param RequestStack                    $requestStack
     */
    public function __construct(
        MetadataFactoryInterface $hateoasMetadataFactory,
        MetadataFactoryInterface $jmsMetadataFactory,
        PropertyNamingStrategyInterface $namingStrategy,
        RequestStack $requestStack,
        RouterInterface $router
    ) {
        $this->hateoasMetadataFactory = $hateoasMetadataFactory;
        $this->jmsMetadataFactory = $jmsMetadataFactory;
        $this->namingStrategy = $namingStrategy;
        $this->requestStack = $requestStack;
        $this->router = $router;
        $this->includedData = [];
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            array(
                'event' => Events::POST_SERIALIZE,
                'format' => 'json',
                'method' => 'onPostSerialize',
            ),
            array(
                'event' => Events::PRE_DESERIALIZE,
                'format' => 'json',
                'method' => 'onPreDeserialize',
            ),
            array(
                'event' => Events::POST_DESERIALIZE,
                'format' => 'json',
                'method' => 'onPostDeserialize',
            ),
        );
    }

    public function onPostSerialize(ObjectEvent $event)
    {
        $visitor = $event->getVisitor();
        $object = $event->getObject();
        $context = $event->getContext();
        $className = get_class($object);

        /** @var ClassMetadata $metadata */
        $metadata = $this->hateoasMetadataFactory->getMetadataForClass($className);

        // if it has no json api metadata, skip it
        if (null === $metadata || !$metadata->getResource()) {
            return;
        }

        /** @var JmsClassMetadata $jmsMetadata */
        $jmsMetadata = $this->jmsMetadataFactory->getMetadataForClass($className);

        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        if ($visitor instanceof JsonApiSerializationVisitor) {
            $visitor->addData(self::EXTRA_DATA_KEY, $this->getRelationshipDataArray(
                $metadata, $this->getId($metadata, $object)
            ));

            $relationships = array();

            foreach ($metadata->getRelationships() as $relationship) {
                $relationshipPropertyName = $relationship->getName();

                $relationshipObject = $propertyAccessor->getValue($object, $relationshipPropertyName);

                if (null === $relationshipObject) {
                    return;
                }

                // JMS Serializer support
                if (!isset($jmsMetadata->propertyMetadata[$relationshipPropertyName])) {
                    continue;
                }
                $jmsPropertyMetadata = $jmsMetadata->propertyMetadata[$relationshipPropertyName];
                $relationshipPayloadKey = $this->namingStrategy->translateName($jmsPropertyMetadata);

                $relationshipData =& $relationships[$relationshipPayloadKey];
                $relationshipData = array();

                // add `links`
                $links = $this->processRelationshipLinks($object, $relationship);
                if ($links) {
                    $relationshipData['links'] = $links;
                }

                $include = [];
                if ($request = $this->requestStack->getCurrentRequest()) {
                    $include = $request->query->get('include');
                    $include = $this->parseInclude($include);
                }

                // FIXME: $includePath always is relative to the primary resource, so we can build our way with
                // class metadata to find out if we can include this relationship.
                foreach ($include as $includePath) {
                    $last = end($includePath);
                    if ($last === $relationship->getName()) {
                        // keep track of the path we are currently following (e.x. comments -> author)
                        $this->currentPath = $includePath;
                        $relationship->setIncludedByDefault(true);
                        // we are done here, since we have found out we can include this relationship :)
                        break;
                    }
                }

                // We show the relationships data if it is included or if there are no links. We do this
                // because there MUST be links or data (see: http://jsonapi.org/format/#document-resource-object-relationships).
                if ($relationship->isIncludedByDefault() || !$links || $relationship->getShowData()) {
                    // hasMany relationship
                    if ($this->isIteratable($relationshipObject)) {
                        $relationshipData['data'] = array();
                        foreach ($relationshipObject as $item) {
                            $relationshipData['data'][] = $this->processRelationship($item, $relationship, $context);
                        }
                    } // belongsTo relationship
                    else {
                        $relationshipData['data'] = $this->processRelationship($relationshipObject, $relationship, $context);
                    }
                }
            }

            if ($relationships) {
                $visitor->addData('relationships', $relationships);
            }

            if ($metadata->getResource() && true === $metadata->getResource()->getShowLinkSelf() && $this->getId($metadata, $object)) {
                $visitor->addData('links', array(self::LINK_SELF => $this->generateUrlSelf($metadata, $object)));
            }

            $root = (array)$visitor->getRoot();

            $visitor->setRoot($root);
        }
    }

    /**
     * @param ClassMetadata $resource
     * @param mixed $object
     * @return string
     */
    private function generateUrlSelf(ClassMetadata $metadata, $object)
    {
        $params = $this->router->getContext()->getParameters();
        
        if ($request = $this->requestStack->getCurrentRequest()) {
            $params = array_merge($params, $request->attributes->get('_route_params'));
        }

        $params['id'] = $this->getId($metadata, $object);
        $resourceIdName = Inflector::camelize($metadata->getResource()->getType()) . 'Id';
        $params[$resourceIdName] = $this->getId($metadata, $object);
        $this->router->getContext()->setParameters($params);
        $link = $this->router->generate($metadata->getResource()->getRoute(), [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $link;
    }
    
    /**
     * @param mixed $primaryObject
     * @param mixed $relationshipObject
     * @param ClassMetadata $primaryMetadata
     * @param ClassMetadata $relationshipMetadata
     * @param Relationship $relationship
     * @return string
     */
    private function generateRelationshipUrl($primaryObject, $relationshipObject, ClassMetadata $primaryMetadata, ClassMetadata $relationshipMetadata, Relationship $relationship)
    {
        $params = $this->router->getContext()->getParameters();

        if ($request = $this->requestStack->getCurrentRequest()) {
            $params = array_merge($params, $request->attributes->get('_route_params'));
        }

        $primaryIdName = Inflector::camelize($primaryMetadata->getResource()->getType() . 'Id');
        $params[$primaryIdName] = $this->getId($primaryMetadata, $primaryObject);

        $relationshipIdName = Inflector::camelize($relationshipMetadata->getResource()->getType() . 'Id');
        $params[$relationshipIdName] = $this->getId($relationshipMetadata, $relationshipObject);

        $this->router->getContext()->setParameters($params);

        $link = $this->router->generate($relationship->getRoute());

        return $link;
    }

    /**
     * @param mixed $primaryObject
     * @param ClassMetadata $primaryMetadata
     * @param ClassMetadata $relationshipMetadata
     * @param Relationship $relationship
     * @return string
     */
    private function generateRelationshipCollectionUrl($primaryObject, ClassMetadata $primaryMetadata, ClassMetadata $relationshipMetadata, Relationship $relationship)
    {
        $params = $this->router->getContext()->getParameters();

        if ($request = $this->requestStack->getCurrentRequest()) {
            $params = array_merge($params, $request->attributes->get('_route_params'));
        }

        $primaryIdName = Inflector::camelize($primaryMetadata->getResource()->getType() . 'Id');

        $params[$primaryIdName] = $this->getId($primaryMetadata, $primaryObject);

        $this->router->getContext()->setParameters($params);

        $link = $this->router->generate($relationship->getRoute());

        return $link;
    }

    /**
     * @param mixed $primaryObject
     * @param Relationship $relationship
     * @return array
     */
    protected function processRelationshipLinks($primaryObject, Relationship $relationship)
    {
        $className = get_class($primaryObject);
        /** @var ClassMetadata $relationshipMetadata */
        $primaryMetadata = $this->hateoasMetadataFactory->getMetadataForClass($className);
        $primaryId = $this->getId($primaryMetadata, $primaryObject);
        $relationshipPropertyName = $relationship->getName();

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $relationshipObject = $propertyAccessor->getValue($primaryObject, $relationshipPropertyName);

        if (is_array($relationshipObject)) {
            if (empty($relationshipObject)) {
                return array();
            }
            $relationshipObject = current($relationshipObject);
        }

        $relationshipClassName = get_class($relationshipObject);
        $relationshipMetadata = $this->hateoasMetadataFactory->getMetadataForClass($relationshipClassName);
            
        $links = array();

        if ($this->isIteratable($relationshipObject)) {
            if ($relationship->getShowLinkSelf()) {
                $links[self::LINK_SELF] = $this->generateRelationshipCollectionUrl($primaryObject, $primaryMetadata, $relationshipMetadata, $relationship);
            }
        } else {
            if ($relationship->getShowLinkSelf()) {
                $links[self::LINK_SELF] = $this->generateRelationshipUrl($primaryObject, $relationshipObject, $primaryMetadata, $relationshipMetadata, $relationship);
            }

            if ($relationship->getShowLinkRelated()) {
                $links[self::LINK_RELATED] = $this->generateUrlSelf($relationshipMetadata, $relationshipObject);
            }
        }
        
        return $links;
    }

    /**
     * @param              $object
     * @param Relationship $relationship
     * @param Context      $context
     *
     * @return array
     */
    protected function processRelationship($object, Relationship $relationship, Context $context)
    {
        /* @var $context SerializationContext */
        if (null === $object) {
            return null;
        }

        if (!is_object($object)) {
            throw new RuntimeException(sprintf('Cannot process relationship "%s", because it is not an object but a %s.', $relationship->getName(), gettype($object)));
        }

        /** @var ClassMetadata $relationshipMetadata */
        $relationshipMetadata = $this->hateoasMetadataFactory->getMetadataForClass(get_class($object));

        if (null === $relationshipMetadata) {
            throw new RuntimeException(sprintf(
                'Metadata for class %s not found. Did you define it as a JSON-API resource?',
                ClassUtils::getRealClass(get_class($object))
            ));
        }

        $relationshipId = $this->getId($relationshipMetadata, $object);

        // contains the relations type and id
        $relationshipDataArray = $this->getRelationshipDataArray($relationshipMetadata, $relationshipId);

        $groups = $context->attributes->get('groups')->getOrElse([]);

        // the relationship data can only contain one reference to another resource
        return $relationshipDataArray;
    }

    /**
     * @param $include
     *
     * @return array
     */
    protected function parseInclude($include)
    {
        $array = array();
        $parts = array_map('trim', explode(',', $include));

        foreach ($parts as $part) {
            $resources = array_map('trim', explode('.', $part));
            $array[] = $resources;
        }

        return $array;
    }

    /**
     * Get the real ID of the given object by it's metadata
     *
     * @param ClassMetadata $classMetadata
     * @param               $object
     *
     * @return mixed
     */
    protected function getId(ClassMetadata $classMetadata, $object)
    {
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        return (string) $propertyAccessor->getValue($object, $classMetadata->getIdField());
    }

    /**
     * @param array $resources
     * @param int $index
     *
     * @return array
     */
    protected function parseIncludeResources(array $resources, $index = 0)
    {
        if (isset($resources[$index + 1])) {
            $resource = array_shift($resources);

            return array(
                $resource => $this->parseIncludeResources($resources),
            );
        }

        return array(
            end($resources) => 1,
        );
    }

    /**
     * @param ClassMetadata $classMetadata
     * @param               $id
     *
     * @return array
     */
    protected function getRelationshipDataArray(ClassMetadata $classMetadata, $id)
    {
        if (null === $classMetadata->getResource()) {
            return null;
        }
        
        return array(
            'type' => $classMetadata->getResource()->getType(),
            'id' => $id,
        );
    }

    /**
     * Checks if an object is really empty, also if it is iteratable and has zero items.
     *
     * @param $object
     *
     * @return bool
     */
    protected function isEmpty($object)
    {
        return empty($object) || ($this->isIteratable($object) && count($object) === 0);
    }

    /**
     * @param $data
     *
     * @return bool
     */
    protected function isIteratable($data)
    {
        return (is_array($data) || $data instanceof Traversable);
    }

    /**
     * @param PreDeserializeEvent $event
     */
    public function onPreDeserialize(PreDeserializeEvent $event)
    {
        $type = $event->getType();
        $context = $event->getContext();
        /* @var $context DeserializationContext */
        
        $resourceClassName = $type['name'];
        $data = $event->getData();

        if (1 === $context->getDepth() & isset($data['data'])) {
            $event->setData($data['data']);
            
            if ($this->hateoasMetadataFactory->getMetadataForClass($resourceClassName)) {
                $event->setType(JsonApiResource::class);
            }  elseif (ArrayCollection::class === $resourceClassName) {
                $context->attributes->set('target', new ArrayCollection());
                
                $event->setType(ArrayCollection::class, [['name' => JsonApiResource::class, 'params' => []]]);
            } elseif (OffsetPaginatedRepresentation::class === $resourceClassName) {
                $target = $context->attributes->get('target')->getOrElse(null);

                if (isset($data['meta']['total-results'])) {
                    $target->setTotalResults($data['meta']['total-results']);
                }
    
                if (isset($data['data'])) {
                    $event->setType(ArrayCollection::class, [['name' => JsonApiResource::class, 'params' => []]]);
                }
            }

            if (isset($data['included'])) {
                $this->includedData = $data['included'];
            }
        }
    }

    /**
     * @param ObjectEvent $event
     */
    public function onPostDeserialize(ObjectEvent $event)
    {
        $context = $event->getContext();
        /* @var $context DeserializationContext */

        while ($includedData = array_pop($this->includedData)) {
            $included = $context->accept($includedData, ['name' => JsonApiResource::class, 'params' => []]);
        }
    }
}
