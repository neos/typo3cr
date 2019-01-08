<?php
namespace Neos\ContentRepository\Domain\Projection\Content;

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\ValueObject\NodeIdentifier;
use Neos\ContentRepository\Domain\ValueObject\ContentStreamIdentifier;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Domain\ValueObject\NodeAggregateIdentifier;
use Neos\ContentRepository\Domain\ValueObject\NodeName;
use Neos\ContentRepository\Domain\ValueObject\NodeTypeName;
use Neos\Cache\CacheAwareInterface;
use Neos\ContentRepository\Domain\ValueObject\PropertyCollectionInterface;

/**
 * The "new" Event-Sourced NodeInterface. Supersedes the old Neos\ContentRepository\Domain\Model\NodeInterface.
 *
 * !! Reference resolving NOT in NodeInterface
 *
 * Immutable. Read-only. Detached from storage.
 */
interface NodeInterface extends CacheAwareInterface
{
    /**
     * Whether or not this node is the root of the graph, i.e. has no parent node
     *
     * @return bool
     */
    public function isRoot(): bool;

    /**
     * @return ContentStreamIdentifier
     */
    public function getContentStreamIdentifier(): ContentStreamIdentifier;

    /**
     * returns the DimensionSpacePoint the node was *requested in*, i.e. one of the DimensionSpacePoints
     * this node is visible in. If you need the DimensionSpacePoint where the node is actually at home,
     * see getOriginDimensionSpacePoint()
     *
     * @return DimensionSpacePoint
     */
    public function getDimensionSpacePoint(): DimensionSpacePoint;

    /**
     * @return NodeAggregateIdentifier
     */
    public function getNodeAggregateIdentifier(): NodeAggregateIdentifier;

    /**
     * @return NodeIdentifier
     */
    public function getNodeIdentifier(): NodeIdentifier;

    /**
     * @return NodeTypeName
     */
    public function getNodeTypeName(): NodeTypeName;

    /**
     * @return NodeType
     */
    public function getNodeType(): NodeType;

    /**
     * @return NodeName
     */
    public function getNodeName(): NodeName;

    /**
     * returns the DimensionSpacePoint the node is at home in. Usually needed to address a Node in a NodeAggregate
     * in order to update it.
     *
     * @return DimensionSpacePoint
     */
    public function getOriginDimensionSpacePoint(): DimensionSpacePoint;

    /**
     * Returns all properties of this node. References are NOT part of this API; there you need to check getReference() and getReferences()
     *
     * @return PropertyCollectionInterface Property values, indexed by their name
     * @api
     */
    public function getProperties(): PropertyCollectionInterface;

    /**
     * Returns the specified property.
     *
     * If the node has a content object attached, the property will be fetched
     * there if it is gettable.
     *
     * @param string $propertyName Name of the property
     * @return mixed value of the property
     * @api
     */
    public function getProperty($propertyName);

    /**
     * If this node has a property with the given name. Does NOT check the NodeType; but checks
     * for a non-NULL property value.
     *
     * @param string $propertyName
     * @return boolean
     * @api
     */
    public function hasProperty($propertyName): bool;

    /**
     * Returns the node label as generated by the configured node label generator
     *
     * @return string
     */
    public function getLabel(): string;
}
