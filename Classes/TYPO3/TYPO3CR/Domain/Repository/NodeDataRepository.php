<?php
namespace TYPO3\TYPO3CR\Domain\Repository;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "TYPO3CR".               *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Persistence\QueryInterface;
use TYPO3\Flow\Persistence\Repository;
use TYPO3\Flow\Reflection\ObjectAccess;
use TYPO3\Flow\Utility\Arrays;
use TYPO3\Flow\Utility\TypeHandling;
use TYPO3\TYPO3CR\Domain\Model\Node;
use TYPO3\TYPO3CR\Domain\Model\NodeData;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Model\NodeType;
use TYPO3\TYPO3CR\Domain\Model\Workspace;
use TYPO3\TYPO3CR\Domain\Service\Context;
use TYPO3\TYPO3CR\Exception;

/**
 * A purely internal repository for NodeData storage
 *
 * DO NOT USE outside the TYPO3CR package!
 *
 * The ContextFactory can be used to create a Context that allows to find Node instances that act as the
 * public API to the TYPO3CR.
 *
 * @Flow\Scope("singleton")
 */
class NodeDataRepository extends Repository {

	/**
	 * Constants for setNewIndex()
	 */
	const POSITION_BEFORE = 1;
	const POSITION_AFTER = 2;
	const POSITION_LAST = 3;

	/**
	 * Maximum possible index
	 */
	const INDEX_MAXIMUM = 2147483647;

	/**
	 * @var \SplObjectStorage
	 */
	protected $addedNodes;

	/**
	 * @var \SplObjectStorage
	 */
	protected $removedNodes;

	/**
	 * Doctrine's Entity Manager. Note that "ObjectManager" is the name of the related
	 * interface ...
	 *
	 * @Flow\Inject
	 * @var \Doctrine\Common\Persistence\ObjectManager
	 */
	protected $entityManager;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Service\NodeTypeManager
	 */
	protected $nodeTypeManager;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Log\SystemLoggerInterface
	 */
	protected $systemLogger;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Factory\NodeFactory
	 */
	protected $nodeFactory;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Security\Context
	 */
	protected $securityContext;

	/**
	 * @var array
	 */
	protected $defaultOrderings = array(
		'index' => QueryInterface::ORDER_ASCENDING
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->addedNodes = new \SplObjectStorage();
		$this->removedNodes = new \SplObjectStorage();
		parent::__construct();
	}

	/**
	 * Adds a NodeData object to this repository.
	 *
	 * This repository keeps track of added and removed nodes (additionally to the other Unit of Work)
	 * in order to find in-memory nodes.
	 *
	 * @param object $object The object to add
	 * @return void
	 * @api
	 */
	public function add($object) {
		if ($this->removedNodes->contains($object)) {
			$this->removedNodes->detach($object);
		}
		if (!$this->addedNodes->contains($object)) {
			$this->addedNodes->attach($object);
		}
		parent::add($object);
	}

	/**
	 * Removes an object to the persistence.
	 *
	 * This repository keeps track of added and removed nodes (additionally to the
	 * other Unit of Work) in order to find in-memory nodes.
	 *
	 * @param object $object The object to remove
	 * @return void
	 * @api
	 */
	public function remove($object) {
		if ($object instanceof Node) {
			$object = $object->getNodeData();
		}
		if ($this->addedNodes->contains($object)) {
			$this->addedNodes->detach($object);
		}
		if (!$this->removedNodes->contains($object)) {
			$this->removedNodes->attach($object);
		}
		parent::remove($object);
	}

	/**
	 * Find a single node by exact path.
	 *
	 * @param string $path Absolute path of the node
	 * @param Workspace $workspace The containing workspace
	 * @param array $dimensions An array of dimensions with array of ordered values to use for fallback matching
	 * @param boolean|NULL $removedNodes Include removed nodes, NULL (all), FALSE (no removed nodes) or TRUE (only removed nodes)
	 * @throws \InvalidArgumentException
	 * @return NodeData The matching node if found, otherwise NULL
	 */
	public function findOneByPath($path, Workspace $workspace, array $dimensions = NULL, $removedNodes = FALSE) {
		if (strlen($path) === 0 || ($path !== '/' && ($path[0] !== '/' || substr($path, -1, 1) === '/'))) {
			throw new \InvalidArgumentException('"' . $path . '" is not a valid path: must start but not end with a slash.', 1284985489);
		}

		if ($path === '/') {
			return $workspace->getRootNodeData();
		}

		$workspaces = array();
		while ($workspace !== NULL) {
			/** @var $node NodeData */
			foreach ($this->addedNodes as $node) {
				if ($node->getPath() === $path && $node->matchesWorkspaceAndDimensions($workspace, $dimensions)) {
					return $node;
				}
			}

			foreach ($this->removedNodes as $node) {
				if ($node->getPath() === $path && $node->matchesWorkspaceAndDimensions($workspace, $dimensions)) {
					return NULL;
				}
			}

			$workspaces[] = $workspace;
			$workspace = $workspace->getBaseWorkspace();
		}

		$queryBuilder = $this->createQueryBuilder($workspaces);
		if ($dimensions !== NULL) {
			$this->addDimensionJoinConstraintsToQueryBuilder($queryBuilder, $dimensions);
		} else {
			$dimensions = array();
		}
		$this->addPathConstraintToQueryBuilder($queryBuilder, $path);

		$query = $queryBuilder->getQuery();
		$nodes = $query->getResult();

		$foundNodes = $this->reduceNodeVariantsByWorkspacesAndDimensions($nodes, $workspaces, $dimensions);
		$foundNodes = $this->filterRemovedNodes($foundNodes, $removedNodes);

		if ($foundNodes !== array()) {
			return reset($foundNodes);
		}
		return NULL;
	}

	/**
	 * Finds a node by its path and context.
	 *
	 * If the node does not exist in the specified context's workspace, this function will
	 * try to find one with the given path in one of the base workspaces (if any).
	 *
	 * Examples for valid paths:
	 *
	 * /          the root node
	 * /foo       node "foo" on the first level
	 * /foo/bar   node "bar" on the second level
	 * /foo/      first node on second level, below "foo"
	 *
	 * @param string $path Absolute path of the node
	 * @param Context $context The containing context
	 * @return NodeInterface|NULL The matching node if found, otherwise NULL
	 * @throws \InvalidArgumentException
	 */
	public function findOneByPathInContext($path, Context $context) {
		$node = $this->findOneByPath($path, $context->getWorkspace(), $context->getDimensions(), ($context->isRemovedContentShown() ? NULL : FALSE));
		if ($node !== NULL) {
			$node = $this->nodeFactory->createFromNodeData($node, $context);
		}

		return $node;
	}

	/**
	 * Finds a node by its identifier and workspace.
	 *
	 * If the node does not exist in the specified workspace, this function will
	 * try to find one with the given identifier in one of the base workspaces (if any).
	 *
	 * @param string $identifier Identifier of the node
	 * @param Workspace $workspace The containing workspace
	 * @param array $dimensions An array of dimensions with array of ordered values to use for fallback matching
	 * @return NodeData The matching node if found, otherwise NULL
	 */
	public function findOneByIdentifier($identifier, Workspace $workspace, array $dimensions = NULL) {
		$workspaces = array();
		while ($workspace !== NULL) {
			/** @var $node NodeData */
			foreach ($this->addedNodes as $node) {
				if ($node->getIdentifier() === $identifier && $node->matchesWorkspaceAndDimensions($workspace, $dimensions)) {
					return $node;
				}
			}

			/** @var $node NodeData */
			foreach ($this->removedNodes as $node) {
				if ($node->getIdentifier() === $identifier && $node->matchesWorkspaceAndDimensions($workspace, $dimensions)) {
					return NULL;
				}
			}

			$workspaces[] = $workspace;
			$workspace = $workspace->getBaseWorkspace();
		}

		$queryBuilder = $this->createQueryBuilder($workspaces);
		if ($dimensions !== NULL) {
			$this->addDimensionJoinConstraintsToQueryBuilder($queryBuilder, $dimensions);
		} else {
			$dimensions = array();
		}
		$this->addIdentifierConstraintToQueryBuilder($queryBuilder, $identifier);

		$query = $queryBuilder->getQuery();
		$nodes = $query->getResult();

		$foundNodes = $this->reduceNodeVariantsByWorkspacesAndDimensions($nodes, $workspaces, $dimensions);
		$foundNodes = $this->filterRemovedNodes($foundNodes, FALSE);

		if ($foundNodes !== array()) {
			return reset($foundNodes);
		}
		return NULL;
	}

	/**
	 * Assigns an index to the given node which reflects the specified position.
	 * If the position is "before" or "after", an index will be chosen which makes
	 * the given node the previous or next node of the given reference node.
	 * If the position "last" is specified, an index higher than any existing index
	 * will be chosen.
	 *
	 * If no free index is available between two nodes (for "before" and "after"),
	 * the whole index of the current node level will be renumbered.
	 *
	 * @param NodeData $node The node to set the new index for
	 * @param integer $position The position the new index should reflect, must be one of the POSITION_* constants
	 * @param NodeInterface $referenceNode The reference node. Mandatory for POSITION_BEFORE and POSITION_AFTER
	 * @return void
	 * @throws \InvalidArgumentException
	 */
	public function setNewIndex(NodeData $node, $position, NodeInterface $referenceNode = NULL) {
		$parentPath = $node->getParentPath();

		switch ($position) {
			case self::POSITION_BEFORE:
				if ($referenceNode === NULL) {
					throw new \InvalidArgumentException('The reference node must be specified for POSITION_BEFORE.', 1317198857);
				}
				$referenceIndex = $referenceNode->getIndex();
				$nextLowerIndex = $this->findNextLowerIndex($parentPath, $referenceIndex);
				if ($nextLowerIndex === NULL) {
						// FIXME: $nextLowerIndex returns 0 and not NULL in case no lower index is found. So this case seems to be
						// never executed. We need to check that again!
					$newIndex = (integer)round($referenceIndex / 2);
				} elseif ($nextLowerIndex < ($referenceIndex - 1)) {
						// there is free space left between $referenceNode and preceding sibling.
					$newIndex = (integer)round($nextLowerIndex + (($referenceIndex - $nextLowerIndex) / 2));
				} else {
						// there is no free space left between $referenceNode and following sibling -> we need to re-number!
					$this->renumberIndexesInLevel($parentPath);
					$referenceIndex = $referenceNode->getIndex();
					$nextLowerIndex = $this->findNextLowerIndex($parentPath, $referenceIndex);
					if ($nextLowerIndex === NULL) {
						$newIndex = (integer)round($referenceIndex / 2);
					} else {
						$newIndex = (integer)round($nextLowerIndex + (($referenceIndex - $nextLowerIndex) / 2));
					}
				}
			break;
			case self::POSITION_AFTER:
				if ($referenceNode === NULL) {
					throw new \InvalidArgumentException('The reference node must be specified for POSITION_AFTER.', 1317198858);
				}
				$referenceIndex = $referenceNode->getIndex();
				$nextHigherIndex = $this->findNextHigherIndex($parentPath, $referenceIndex);
				if ($nextHigherIndex === NULL) {
						// $referenceNode is last node, so we can safely add an index at the end by incrementing the reference index.
					$newIndex = $referenceIndex + 100;
				} elseif ($nextHigherIndex > ($referenceIndex + 1)) {
						// $referenceNode is not last node, but there is free space left between $referenceNode and following sibling.
					$newIndex = (integer)round($referenceIndex + (($nextHigherIndex - $referenceIndex) / 2));
				} else {
						// $referenceNode is not last node, and no free space is left -> we need to re-number!
					$this->renumberIndexesInLevel($parentPath);
					$referenceIndex = $referenceNode->getIndex();
					$nextHigherIndex = $this->findNextHigherIndex($parentPath, $referenceIndex);
					if ($nextHigherIndex === NULL) {
						$newIndex = $referenceIndex + 100;
					} else {
						$newIndex = (integer)round($referenceIndex + (($nextHigherIndex - $referenceIndex) / 2));
					}
				}
			break;
			case self::POSITION_LAST:
				$highestIndex = $this->findHighestIndexInLevel($parentPath);
				$newIndex = $highestIndex + 100;
			break;
			default:
				throw new \InvalidArgumentException('Invalid position for new node index given.', 1329729088);
		}

		$node->setIndex($newIndex);
	}

	/**
	 * Finds recursively nodes by its parent and (optionally) by its node type.
	 *
	 * @see findByParentAndNodeType()
	 *
	 * @param string $parentPath Absolute path of the parent node
	 * @param string $nodeTypeFilter Filter the node type of the nodes, allows complex expressions (e.g. "TYPO3.Neos:Page", "!TYPO3.Neos:Page,TYPO3.Neos:Text" or NULL)
	 * @param Workspace $workspace The containing workspace
	 * @param array $dimensions An array of dimensions to dimension values
	 * @param boolean $removedNodes If TRUE the result has ONLY removed nodes. If FALSE removed nodes are NOT inside the result. If NULL the result contains BOTH removed and non-removed nodes. (defaults to FALSE)
	 * @return array<\TYPO3\TYPO3CR\Domain\Model\NodeData> The nodes found on the given path
	 */
	public function findByParentAndNodeTypeRecursively($parentPath, $nodeTypeFilter, Workspace $workspace, array $dimensions = NULL, $removedNodes = FALSE) {
		return $this->findByParentAndNodeType($parentPath, $nodeTypeFilter, $workspace, $dimensions, $removedNodes, TRUE);
	}

	/**
	 * Finds nodes by its parent and (optionally) by its node type.
	 * If the $recursive flag is set to TRUE, all matching nodes underneath $parentPath will be returned
	 *
	 * Note: Filters out removed nodes.
	 *
	 * The primary sort key is the *index*, the secondary sort key (if indices are equal, which
	 * only occurs in very rare cases) is the *identifier*.
	 *
	 * @param string $parentPath Absolute path of the parent node
	 * @param string $nodeTypeFilter Filter the node type of the nodes, allows complex expressions (e.g. "TYPO3.Neos:Page", "!TYPO3.Neos:Page,TYPO3.Neos:Text" or NULL)
	 * @param Workspace $workspace The containing workspace
	 * @param array $dimensions An array of dimensions to dimension values
	 * @param boolean $removedNodes If TRUE the result has ONLY removed nodes. If FALSE removed nodes are NOT inside the result. If NULL the result contains BOTH removed and non-removed nodes. (defaults to FALSE)
	 * @param boolean $recursive If TRUE *all* matching nodes underneath the specified parent path are returned
	 * @return array<\TYPO3\TYPO3CR\Domain\Model\NodeData> The nodes found on the given path
	 * @todo Improve implementation by using DQL
	 */
	public function findByParentAndNodeType($parentPath, $nodeTypeFilter, Workspace $workspace, array $dimensions = NULL, $removedNodes = FALSE, $recursive = FALSE) {
		$foundNodes = $this->getNodeDataForParentAndNodeType($parentPath, $nodeTypeFilter, $workspace, $dimensions, $removedNodes, $recursive);

		if ($parentPath === '/') {
			/** @var $addedNode NodeData */
			foreach ($this->addedNodes as $addedNode) {
				if ($addedNode->getDepth() === 1 && $addedNode->matchesWorkspaceAndDimensions($workspace, $dimensions)) {
					$foundNodes[$addedNode->getIdentifier()] = $addedNode;
				}
			}
			/** @var $removedNode NodeData */
			foreach ($this->removedNodes as $removedNode) {
				if (isset($foundNodes[$removedNode->getIdentifier()]) && $removedNode->matchesWorkspaceAndDimensions($workspace, $dimensions)) {
					unset($foundNodes[$removedNode->getIdentifier()]);
				}
			}
		} else {
			$childNodeDepth = substr_count($parentPath, '/') + 1;
			/** @var $addedNode NodeData */
			foreach ($this->addedNodes as $addedNode) {
				if ($addedNode->getDepth() === $childNodeDepth && substr($addedNode->getPath(), 0, strlen($parentPath) + 1) === ($parentPath . '/') && $addedNode->matchesWorkspaceAndDimensions($workspace, $dimensions)) {
					$foundNodes[$addedNode->getIdentifier()] = $addedNode;
				}
			}
			/** @var $removedNode NodeData */
			foreach ($this->removedNodes as $removedNode) {
				if ($removedNode->getDepth() === $childNodeDepth && substr($removedNode->getPath(), 0, strlen($parentPath) + 1) === ($parentPath . '/') && $removedNode->matchesWorkspaceAndDimensions($workspace, $dimensions)) {
					if (isset($foundNodes[$removedNode->getIdentifier()])) {
						unset($foundNodes[$removedNode->getIdentifier()]);
					}
				}
			}
		}

		$foundNodes = $this->sortNodesByIndex($foundNodes);

		return $foundNodes;
	}

	/**
	 * Internal method
	 *
	 * @param string $parentPath
	 * @param string $nodeTypeFilter
	 * @param Workspace $workspace
	 * @param array $dimensions
	 * @param boolean|NULL $removedNodes
	 * @param boolean $recursive
	 * @return array
	 */
	protected function getNodeDataForParentAndNodeType($parentPath, $nodeTypeFilter, Workspace $workspace, array $dimensions = NULL, $removedNodes, $recursive) {
		$workspaces = array();
		while ($workspace !== NULL) {
			$workspaces[] = $workspace;
			$workspace = $workspace->getBaseWorkspace();
		}

		$queryBuilder = $this->createQueryBuilder($workspaces);
		if ($dimensions !== NULL) {
			$this->addDimensionJoinConstraintsToQueryBuilder($queryBuilder, $dimensions);
		} else {
			$dimensions = array();
		}
		$this->addParentPathConstraintToQueryBuilder($queryBuilder, $parentPath, $recursive);
		if ($nodeTypeFilter !== NULL) {
			$this->addNodeTypeFilterConstraintsToQueryBuilder($queryBuilder, $nodeTypeFilter);
		}

		$query = $queryBuilder->getQuery();
		$nodes = $query->getResult();

		$foundNodes = $this->reduceNodeVariantsByWorkspacesAndDimensions($nodes, $workspaces, $dimensions);
		$foundNodes = $this->filterRemovedNodes($foundNodes, $removedNodes);

		return $foundNodes;
	}

	/**
	 * Find NodeData by parent path without any dimension reduction and grouping by identifier
	 *
	 * Only used internally for setting the path of all child nodes
	 *
	 * @param string $parentPath
	 * @param Workspace $workspace
	 * @return array<\TYPO3\TYPO3CR\Domain\Model\NodeData> A unreduced array of NodeData
	 */
	public function findByParentWithoutReduce($parentPath, Workspace $workspace) {
		$workspaces = array();
		while ($workspace !== NULL) {
			$workspaces[] = $workspace;
			$workspace = $workspace->getBaseWorkspace();
		}

		$queryBuilder = $this->createQueryBuilder($workspaces);
		$this->addParentPathConstraintToQueryBuilder($queryBuilder, $parentPath);

		$query = $queryBuilder->getQuery();
		$foundNodes = $query->getResult();

		if ($parentPath === '/') {
			/** @var $addedNode NodeData */
			foreach ($this->addedNodes as $addedNode) {
				if ($addedNode->getDepth() === 1) {
					$foundNodes[] = $addedNode;
				}
			}
			/** @var $removedNode NodeData */
			foreach ($this->removedNodes as $removedNode) {
				$foundNodes = array_filter($foundNodes, function($nodeData) use($removedNode) { return $nodeData !== $removedNode; });
			}
		} else {
			$childNodeDepth = substr_count($parentPath, '/') + 1;
			/** @var $addedNode NodeData */
			foreach ($this->addedNodes as $addedNode) {
				if ($addedNode->getDepth() === $childNodeDepth && substr($addedNode->getPath(), 0, strlen($parentPath) + 1) === ($parentPath . '/')) {
					$foundNodes[] = $addedNode;
				}
			}
			/** @var $removedNode NodeData */
			foreach ($this->removedNodes as $removedNode) {
				if ($removedNode->getDepth() === $childNodeDepth && substr($removedNode->getPath(), 0, strlen($parentPath) + 1) === ($parentPath . '/')) {
					$foundNodes = array_filter($foundNodes, function($nodeData) use($removedNode) { return $nodeData !== $removedNode; });
				}
			}
		}

		return $foundNodes;
	}

	/**
	 * Find NodeData by identifier path without any dimension reduction
	 *
	 * Only used internally for finding whether the node exists in another dimension
	 *
	 * @param string $identifier
	 * @param Workspace $workspace
	 * @return array<\TYPO3\TYPO3CR\Domain\Model\NodeData> A unreduced array of NodeData
	 */
	public function findByIdentifierWithoutReduce($identifier, Workspace $workspace) {
		$workspaces = array();
		while ($workspace !== NULL) {
			$workspaces[] = $workspace;
			$workspace = $workspace->getBaseWorkspace();
		}

		$queryBuilder = $this->createQueryBuilder($workspaces);
		$this->addIdentifierConstraintToQueryBuilder($queryBuilder, $identifier);

		$query = $queryBuilder->getQuery();
		$foundNodes = $query->getResult();

		return $foundNodes;
	}

	/**
	 * Finds nodes by its parent and (optionally) by its node type given a Context
	 *
	 * TODO Move to a new Node operation getDescendantNodes(...)
	 *
	 * @param string $parentPath Absolute path of the parent node
	 * @param string $nodeTypeFilter Filter the node type of the nodes, allows complex expressions (e.g. "TYPO3.Neos:Page", "!TYPO3.Neos:Page,TYPO3.Neos:Text" or NULL)
	 * @param Context $context The containing workspace
	 * @param boolean $recursive If TRUE *all* matching nodes underneath the specified parent path are returned
	 * @return array<\TYPO3\TYPO3CR\Domain\Model\NodeInterface> The nodes found on the given path
	 */
	public function findByParentAndNodeTypeInContext($parentPath, $nodeTypeFilter, Context $context, $recursive = FALSE) {
		$nodeDataElements = $this->findByParentAndNodeType($parentPath, $nodeTypeFilter, $context->getWorkspace(), $context->getDimensions(), ($context->isRemovedContentShown() ? NULL : FALSE), $recursive);
		$finalNodes = array();
		foreach ($nodeDataElements as $nodeData) {
			$node = $this->nodeFactory->createFromNodeData($nodeData, $context);
			if ($node !== NULL) {
				$finalNodes[] = $node;
			}
		}

		return $finalNodes;
	}

	/**
	 * Counts nodes by its parent and (optionally) by its node type.
	 *
	 * NOTE: Only considers persisted nodes!
	 *
	 * @param string $parentPath Absolute path of the parent node
	 * @param string $nodeTypeFilter Filter the node type of the nodes, allows complex expressions (e.g. "TYPO3.Neos:Page", "!TYPO3.Neos:Page,TYPO3.Neos:Text" or NULL)
	 * @param Workspace $workspace The containing workspace
	 * @param array $dimensions
	 * @param boolean $includeRemovedNodes Should removed nodes be included in the result (defaults to FALSE)
	 * @return integer The number of nodes a similar call to findByParentAndNodeType() would return without any pending added nodes
	 */
	public function countByParentAndNodeType($parentPath, $nodeTypeFilter, Workspace $workspace, array $dimensions = NULL, $includeRemovedNodes = FALSE) {
		return count($this->findByParentAndNodeType($parentPath, $nodeTypeFilter, $workspace, $dimensions, $includeRemovedNodes));
	}

	/**
	 * Renumbers the indexes of all nodes directly below the node specified by the
	 * given path.
	 *
	 * Note that renumbering must happen in-memory and can't be optimized by a clever
	 * query executed directly by the database because sorting indexes of new or
	 * modified nodes need to be considered.
	 *
	 * @param string $parentPath Path to the parent node
	 * @return void
	 * @throws Exception\NodeException
	 */
	protected function renumberIndexesInLevel($parentPath) {
		$this->systemLogger->log(sprintf('Renumbering nodes in level below %s.', $parentPath), LOG_INFO);

		/** @var Query $query */
		$query = $this->entityManager->createQuery('SELECT n.Persistence_Object_Identifier identifier, n.index, n.path FROM TYPO3\TYPO3CR\Domain\Model\NodeData n WHERE n.parentPathHash = :parentPathHash ORDER BY n.index ASC');
		$query->setParameter('parentPathHash', md5($parentPath));

		$nodesOnLevel = array();
		/** @var $node NodeData */
		foreach ($query->getArrayResult() as $node) {
			$nodesOnLevel[$node['index']] = array(
				'identifier' => $node['identifier'],
				'path' => $node['path']
			);
		}

		/** @var $node NodeData */
		foreach ($this->addedNodes as $node) {
			if ($node->getParentPath() === $parentPath) {
				$index = $node->getIndex();
				if (isset($nodesOnLevel[$index])) {
					throw new Exception\NodeException(sprintf('Index conflict for nodes %s and %s: both have index %s', $nodesOnLevel[$index]->getPath(), $node->getPath(), $index), 1317140401);
				}
				$nodesOnLevel[$index] = array(
					'addedNode' => $node,
					'path' => $node->getPath()
				);
			}
		}

			// We need to sort the nodes now, to take unpersisted node orderings into account.
			// This fixes bug #34291
		ksort($nodesOnLevel);

		$newIndex = 100;
		$query = $this->entityManager->createQuery('UPDATE TYPO3\TYPO3CR\Domain\Model\NodeData n SET n.index = :index WHERE n.Persistence_Object_Identifier = :identifier');
		foreach ($nodesOnLevel as $node) {
			if ($newIndex > self::INDEX_MAXIMUM) {
				throw new Exception\NodeException(sprintf('Reached maximum node index of %s while setting index of node %s.', $newIndex, $node['path']), 1317140402);
			}
			if (isset($node['addedNode'])) {
				$node['addedNode']->setIndex($newIndex);
			} else {
				if ($entity = $this->entityManager->getUnitOfWork()->tryGetById($node['identifier'], 'TYPO3\TYPO3CR\Domain\Model\NodeData')) {
					$entity->setIndex($newIndex);
				}
				$query->setParameter('index', $newIndex);
				$query->setParameter('identifier', $node['identifier']);
				$query->execute();
			}
			$newIndex += 100;
		}
	}

	/**
	 * Finds the currently highest index in the level below the given parent path
	 * across all workspaces.
	 *
	 * @param string $parentPath Path of the parent node specifying the level in the node tree
	 * @return integer The currently highest index
	 */
	protected function findHighestIndexInLevel($parentPath) {
		$this->persistEntities();
		/** @var \Doctrine\ORM\Query $query */
		$query = $this->entityManager->createQuery('SELECT MAX(n.index) FROM TYPO3\TYPO3CR\Domain\Model\NodeData n WHERE n.parentPathHash = :parentPathHash');
		$query->setParameter('parentPathHash', md5($parentPath));
		return $query->getSingleScalarResult() ?: 0;
	}

	/**
	 * Returns the next-lower-index seen from the given reference index in the
	 * level below the specified parent path. If no node with a lower than the
	 * given index exists at that level, the reference index is returned.
	 *
	 * The result is determined workspace-agnostic.
	 *
	 * @param string $parentPath Path of the parent node specifying the level in the node tree
	 * @param integer $referenceIndex Index of a known node
	 * @return integer The currently next lower index
	 */
	protected function findNextLowerIndex($parentPath, $referenceIndex) {
		$this->persistEntities();
		/** @var \Doctrine\ORM\Query $query */
		$query = $this->entityManager->createQuery('SELECT MAX(n.index) FROM TYPO3\TYPO3CR\Domain\Model\NodeData n WHERE n.parentPathHash = :parentPathHash AND n.index < :referenceIndex');
		$query->setParameter('parentPathHash', md5($parentPath));
		$query->setParameter('referenceIndex', $referenceIndex);
		return $query->getSingleScalarResult() ?: 0;
	}

	/**
	 * Returns the next-higher-index seen from the given reference index in the
	 * level below the specified parent path. If no node with a higher than the
	 * given index exists at that level, the reference index is returned.
	 *
	 * The result is determined workspace-agnostic.
	 *
	 * @param string $parentPath Path of the parent node specifying the level in the node tree
	 * @param integer $referenceIndex Index of a known node
	 * @return integer The currently next higher index
	 */
	protected function findNextHigherIndex($parentPath, $referenceIndex) {
		$this->persistEntities();
		/** @var \Doctrine\ORM\Query $query */
		$query = $this->entityManager->createQuery('SELECT MIN(n.index) FROM TYPO3\TYPO3CR\Domain\Model\NodeData n WHERE n.parentPathHash = :parentPathHash AND n.index > :referenceIndex');
		$query->setParameter('parentPathHash', md5($parentPath));
		$query->setParameter('referenceIndex', $referenceIndex);
		return $query->getSingleScalarResult() ?: NULL;
	}

	/**
	 * Counts the number of nodes within the specified workspace
	 *
	 * Note: Also counts removed nodes
	 *
	 * @param Workspace $workspace The containing workspace
	 * @return integer The number of nodes found
	 */
	public function countByWorkspace(Workspace $workspace) {
		$query = $this->createQuery();
		$nodesInDatabase = $query->matching($query->equals('workspace', $workspace))->execute()->count();

		$nodesInMemory = 0;
		/** @var $node NodeData */
		foreach ($this->addedNodes as $node) {
			if ($node->getWorkspace()->getName() === $workspace->getName() ) {
				$nodesInMemory++;
			}
		}

		return $nodesInDatabase + $nodesInMemory;
	}

	/**
	 * Sorts the given nodes by their index
	 *
	 * @param array $nodes Nodes
	 * @return array Nodes sorted by index
	 */
	protected function sortNodesByIndex(array $nodes) {
		usort($nodes, function(NodeData $node1, NodeData $node2)
			{
				if ($node1->getIndex() < $node2->getIndex()) {
					return -1;
				} elseif ($node1->getIndex() > $node2->getIndex()) {
					return 1;
				} else {
					return strcmp($node1->getIdentifier(), $node2->getIdentifier());
				}
			});
		return $nodes;
	}

	/**
	 * Finds a single node by its parent and (optionally) by its node type
	 *
	 * @param string $parentPath Absolute path of the parent node
	 * @param string $nodeTypeFilter Filter the node type of the nodes, allows complex expressions (e.g. "TYPO3.Neos:Page", "!TYPO3.Neos:Page,TYPO3.Neos:Text" or NULL)
	 * @param array $dimensions
	 * @param Workspace $workspace The containing workspace
	 * @param boolean $removedNodes If TRUE the result has ONLY removed nodes. If FALSE removed nodes are NOT inside the result. If NULL the result contains BOTH removed and non-removed nodes. (defaults to FALSE)
	 * @return NodeData The node found or NULL
	 */
	public function findFirstByParentAndNodeType($parentPath, $nodeTypeFilter, Workspace $workspace, array $dimensions, $removedNodes = FALSE) {
		$nodes = $this->findByParentAndNodeType($parentPath, $nodeTypeFilter, $workspace, $dimensions, $removedNodes);
		if ($nodes !== array()) {
			return reset($nodes);
		}
		return NULL;
	}

	/**
	 * Finds a single node by its parent and (optionally) by its node type
	 *
	 * @param string $parentPath Absolute path of the parent node
	 * @param string $nodeTypeFilter Filter the node type of the nodes, allows complex expressions (e.g. "TYPO3.Neos:Page", "!TYPO3.Neos:Page,TYPO3.Neos:Text" or NULL)
	 * @param Context $context The containing context
	 * @return NodeData The node found or NULL
	 */
	public function findFirstByParentAndNodeTypeInContext($parentPath, $nodeTypeFilter, Context $context) {
		$firstNode = $this->findFirstByParentAndNodeType($parentPath, $nodeTypeFilter, $context->getWorkspace(), $context->getDimensions(), ($context->isRemovedContentShown() ? NULL : FALSE));

		if ($firstNode !== NULL) {
			$firstNode = $this->nodeFactory->createFromNodeData($firstNode, $context);
		}

		return $firstNode;
	}

	/**
	 * Finds all nodes of the specified workspace lying on the path specified by
	 * (and including) the given starting point and end point and (optionally) a node type filter.
	 *
	 * If some node does not exist in the specified workspace, this function will
	 * try to find a corresponding node in one of the base workspaces (if any).
	 *
	 * @param string $pathStartingPoint Absolute path specifying the starting point
	 * @param string $pathEndPoint Absolute path specifying the end point
	 * @param Workspace $workspace The containing workspace
	 * @param array $dimensions Array of dimensions to array of dimension values
	 * @param boolean $includeRemovedNodes Should removed nodes be included in the result (defaults to FALSE)
	 * @param string $nodeTypeFilter Optional filter for the node type of the nodes, supports complex expressions (e.g. "TYPO3.Neos:Page", "!TYPO3.Neos:Page,TYPO3.Neos:Text" or NULL)
	 * @throws \InvalidArgumentException
	 * @return array<\TYPO3\TYPO3CR\Domain\Model\NodeData> The nodes found on the given path
	 * @todo findOnPath should probably not return child nodes of removed nodes unless removed nodes are included.
	 */
	public function findOnPath($pathStartingPoint, $pathEndPoint, Workspace $workspace, array $dimensions = NULL, $includeRemovedNodes = FALSE, $nodeTypeFilter = NULL) {
		if ($pathStartingPoint !== substr($pathEndPoint, 0, strlen($pathStartingPoint))) {
			throw new \InvalidArgumentException('Invalid paths: path of starting point must first part of end point path.', 1284391181);
		}

		$pathSegments = explode('/', substr($pathEndPoint, strlen($pathStartingPoint)));

		$workspaces = array();
		while ($workspace !== NULL) {
			$workspaces[] = $workspace;
			$workspace = $workspace->getBaseWorkspace();
		}

		$queryBuilder = $this->createQueryBuilder($workspaces);

		if ($dimensions !== NULL) {
			$this->addDimensionJoinConstraintsToQueryBuilder($queryBuilder, $dimensions);
		} else {
			$dimensions = array();
		}

		if ($nodeTypeFilter !== NULL) {
			$this->addNodeTypeFilterConstraintsToQueryBuilder($queryBuilder, $nodeTypeFilter);
		}

		$pathConstraints = array();
		$constraintPath = $pathStartingPoint;
		foreach ($pathSegments as $pathSegment) {
			$constraintPath .= $pathSegment;
			$pathConstraints[] = md5($constraintPath);
			$constraintPath .= '/';
		}
		if (count($pathConstraints) > 0) {
			$queryBuilder->andWhere('n.pathHash IN (:paths)')
				->setParameter('paths', $pathConstraints);
		}

		$query = $queryBuilder->getQuery();
		$foundNodes = $query->getResult();
		$foundNodes = $this->reduceNodeVariantsByWorkspacesAndDimensions($foundNodes, $workspaces, $dimensions);

		if ($includeRemovedNodes === FALSE) {
			$foundNodes = $this->filterRemovedNodes($foundNodes, FALSE);
		}

		$nodesByDepth = array();
		/** @var NodeData $node */
		foreach ($foundNodes as $node) {
			$nodesByDepth[$node->getDepth()] = $node;
		}
		ksort($nodesByDepth);
		return array_values($nodesByDepth);
	}

	/**
	 * Find nodes by a value in properties
	 *
	 * This method is internal and will be replaced with better search capabilities.
	 *
	 * @param string $term Search term
	 * @param string $nodeTypeFilter Node type filter
	 * @param Workspace $workspace
	 * @param array $dimensions
	 * @return array<\TYPO3\TYPO3CR\Domain\Model\NodeData>
	 */
	public function findByProperties($term, $nodeTypeFilter, $workspace, $dimensions) {
		$workspaces = array();
		while ($workspace !== NULL) {
			$workspaces[] = $workspace;
			$workspace = $workspace->getBaseWorkspace();
		}

		$queryBuilder = $this->createQueryBuilder($workspaces);
		$this->addDimensionJoinConstraintsToQueryBuilder($queryBuilder, $dimensions);
		$this->addNodeTypeFilterConstraintsToQueryBuilder($queryBuilder, $nodeTypeFilter);
		$queryBuilder->andWhere('n.properties LIKE :term')->setParameter('term', '%' . $term . '%');

		$query = $queryBuilder->getQuery();
		$foundNodes = $query->getResult();
		$foundNodes = $this->reduceNodeVariantsByWorkspacesAndDimensions($foundNodes, $workspaces, $dimensions);
		$foundNodes = $this->filterRemovedNodes($foundNodes, FALSE);

		return $foundNodes;

	}

	/**
	 * Flushes the addedNodes and removedNodes registry.
	 *
	 * This method is (and should only be) used as a slot to the allObjectsPersisted
	 * signal.
	 *
	 * @return void
	 */
	public function flushNodeRegistry() {
		$this->addedNodes = new \SplObjectStorage();
		$this->removedNodes = new \SplObjectStorage();
	}

	/**
	 * Add node type filter constraints to the query builder
	 *
	 * @param QueryBuilder $queryBuilder
	 * @param string $nodeTypeFilter
	 * @return void
	 */
	protected function addNodeTypeFilterConstraintsToQueryBuilder(QueryBuilder $queryBuilder, $nodeTypeFilter) {
		$constraints = $this->getNodeTypeFilterConstraintsForDql($nodeTypeFilter);
		if (count($constraints['includeNodeTypes']) > 0) {
			$queryBuilder->andWhere('n.nodeType IN (:includeNodeTypes)')
				->setParameter('includeNodeTypes', $constraints['includeNodeTypes']);
		}
		if (count($constraints['excludeNodeTypes']) > 0) {
			$queryBuilder->andWhere('n.nodeType NOT IN (:excludeNodeTypes)')
				->setParameter('excludeNodeTypes', $constraints['excludeNodeTypes']);
		}
	}

	/**
	 * Generates a two dimensional array with the filters. First level is:
	 * 'excludeNodeTypes'
	 * 'includeNodeTypes'
	 *
	 * Both are numeric arrays with the respective node types that are included or excluded.
	 *
	 * @param string $nodeTypeFilter
	 * @return array
	 */
	protected function getNodeTypeFilterConstraintsForDql($nodeTypeFilter) {
		$constraints = array(
			'excludeNodeTypes' => array(),
			'includeNodeTypes' => array()
		);

		$nodeTypeFilterParts = Arrays::trimExplode(',', $nodeTypeFilter);
		foreach ($nodeTypeFilterParts as $nodeTypeFilterPart) {
			$nodeTypeFilterPart = trim($nodeTypeFilterPart);
			if (strpos($nodeTypeFilterPart, '!') === 0) {
				$negate = TRUE;
				$nodeTypeFilterPart = substr($nodeTypeFilterPart, 1);
			} else {
				$negate = FALSE;
			}
			$nodeTypeFilterPartSubTypes = array_merge(array($nodeTypeFilterPart), $this->nodeTypeManager->getSubNodeTypes($nodeTypeFilterPart));

			foreach ($nodeTypeFilterPartSubTypes as $nodeTypeFilterPartSubType) {
				if ($negate === TRUE) {
					$constraints['excludeNodeTypes'][] = $nodeTypeFilterPartSubType;
				} else {
					$constraints['includeNodeTypes'][] = $nodeTypeFilterPartSubType;
				}
			}
		}

		return $constraints;
	}

	/**
	 * @param QueryInterface $query
	 * @param $nodeTypeFilter
	 * @return array
	 */
	protected function getNodeTypeFilterConstraints(QueryInterface $query, $nodeTypeFilter) {
		$includeNodeTypeConstraints = array();
		$excludeNodeTypeConstraints = array();
		$nodeTypeFilterParts = Arrays::trimExplode(',', $nodeTypeFilter);
		foreach ($nodeTypeFilterParts as $nodeTypeFilterPart) {
			$nodeTypeFilterPart = trim($nodeTypeFilterPart);
			if (strpos($nodeTypeFilterPart, '!') === 0) {
				$negate = TRUE;
				$nodeTypeFilterPart = substr($nodeTypeFilterPart, 1);
			} else {
				$negate = FALSE;
			}
			$nodeTypeFilterPartSubTypes = array_merge(array($nodeTypeFilterPart), $this->nodeTypeManager->getSubNodeTypes($nodeTypeFilterPart, FALSE));

			foreach ($nodeTypeFilterPartSubTypes as $nodeTypeFilterPartSubType) {
				if ($negate === TRUE) {
					$excludeNodeTypeConstraints[] = $query->logicalNot($query->equals('nodeType', $nodeTypeFilterPartSubType));
				} else {
					$includeNodeTypeConstraints[] = $query->equals('nodeType', $nodeTypeFilterPartSubType);
				}
			}
		}

		$constraints = $excludeNodeTypeConstraints;
		if (count($includeNodeTypeConstraints) > 0) {
			$constraints[] = $query->logicalOr($includeNodeTypeConstraints);
		}

		return $constraints;
	}

	/**
	 * Iterates of the array of objects and removes all those which have recently been removed from the repository,
	 * but whose removal has not yet been persisted.
	 *
	 * Technically this is a check of the given array against $this->removedNodes.
	 *
	 * @param array &$objects An array of objects to filter, passed by reference.
	 * @return void
	 */
	protected function filterOutRemovedObjects(array &$objects) {
		foreach ($objects as $index => $object) {
			if ($this->removedNodes->contains($object)) {
				unset($objects[$index]);
			}
		}
	}

	/**
	 * Removes NodeData with the removed property set from the given array.
	 *
	 * @param array $nodes NodeData including removed entries
	 * @param boolean|NULL $removedNodes If TRUE the result has ONLY removed nodes. If FALSE removed nodes are NOT inside the result. If NULL the result contains BOTH removed and non-removed nodes.
	 * @return array NodeData with removed entries removed
	 */
	protected function filterRemovedNodes($nodes, $removedNodes) {
		if ($removedNodes === TRUE) {
			return array_filter($nodes, function(NodeData $node) use ($removedNodes) {
				return $node->isRemoved();
			});
		} elseif ($removedNodes === FALSE) {
			return array_filter($nodes, function(NodeData $node) use ($removedNodes) {
				return !$node->isRemoved();
			});
		} else {
			return $nodes;
		}
	}

	/**
	 * Persists all entities managed by the repository and all cascading dependencies
	 *
	 * @return void
	 */
	public function persistEntities() {
		foreach ($this->entityManager->getUnitOfWork()->getIdentityMap() as $className => $entities) {
			if ($className === $this->entityClassName) {
				foreach ($entities as $entityToPersist) {
					$this->entityManager->flush($entityToPersist);
				}
				$this->emitRepositoryObjectsPersisted();
				break;
			}
		}
	}

	/**
	 * Signals that persistEntities() in this repository finished correctly.
	 *
	 * @Flow\Signal
	 * @return void
	 */
	protected function emitRepositoryObjectsPersisted() {
	}

	/**
	 * Reset instances (internal).
	 *
	 * @return void
	 */
	public function reset() {
		$this->addedNodes = new \SplObjectStorage();
		$this->removedNodes = new \SplObjectStorage();
	}

	/**
	 * If $dimensions is not empty, adds join constraints to the given $queryBuilder
	 * limiting the query result to matching hits.
	 *
	 * @param QueryBuilder $queryBuilder
	 * @param array $dimensions
	 * @return void
	 */
	protected function addDimensionJoinConstraintsToQueryBuilder(QueryBuilder $queryBuilder, array $dimensions) {
		$count = 0;
		foreach ($dimensions as $dimensionName => $dimensionValues) {
			$dimensionAlias = 'd' . $count;
			$queryBuilder->andWhere('n IN (SELECT IDENTITY(' . $dimensionAlias . '.nodeData) FROM TYPO3\TYPO3CR\Domain\Model\NodeDimension ' . $dimensionAlias . ' WHERE ' . $dimensionAlias . '.name = \'' . $dimensionName . '\' AND ' . $dimensionAlias . '.value IN (:' . $dimensionAlias . '))');
			$queryBuilder->setParameter($dimensionAlias, $dimensionValues);
			$count++;
		}
	}

	/**
	 * Given an array with duplicate nodes (from different workspaces and dimensions) those are reduced to uniqueness (by node identifier)
	 *
	 * @param array $nodes NodeData result with multiple and duplicate identifiers (different nodes and redundant results for node variants with different dimensions)
	 * @param array $workspaces
	 * @param array $dimensions
	 * @return array Array of unique node results indexed by identifier
	 */
	protected function reduceNodeVariantsByWorkspacesAndDimensions(array $nodes, array $workspaces, array $dimensions) {
		$foundNodes = array();

		$minimalDimensionPositionsByIdentifier = array();
		foreach ($nodes as $node) {
			/** @var NodeData $node */
			$nodeDimensions = $node->getDimensionValues();

			// Find the position of the workspace, a smaller value means more priority
			$workspacePosition = array_search($node->getWorkspace(), $workspaces);
			if ($workspacePosition === FALSE) {
				throw new Exception\NodeException('Node workspace not found in allowed workspaces, this could result from a detached workspace entity in the context.', 1413902143);
			}

			// Find positions in dimensions, add workspace in front for highest priority
			$dimensionPositions = array();
			foreach ($dimensions as $dimensionName => $dimensionValues) {
				foreach ($nodeDimensions[$dimensionName] as $nodeDimensionValue) {
					$position = array_search($nodeDimensionValue, $dimensionValues);
					$dimensionPositions[$dimensionName] = isset($dimensionPositions[$dimensionName]) ? min($dimensionPositions[$dimensionName], $position) : $position;
				}
			}
			$dimensionPositions[] = $workspacePosition;

			// Yes, it seems to work comparing arrays that way!
			if (!isset($minimalDimensionPositionsByIdentifier[$node->getIdentifier()]) || $dimensionPositions < $minimalDimensionPositionsByIdentifier[$node->getIdentifier()]) {
				$foundNodes[$node->getIdentifier()] = $node;
				$minimalDimensionPositionsByIdentifier[$node->getIdentifier()] = $dimensionPositions;
			}
		}

		return $foundNodes;
	}

	/**
	 * Given an array with duplicate nodes (from different workspaces) those are reduced to uniqueness (by node identifier and dimensions hash)
	 *
	 * @param array $nodes NodeData
	 * @param array $workspaces
	 * @return array Array of unique node results indexed by identifier and dimensions hash
	 */
	protected function reduceNodeVariantsByWorkspaces(array $nodes, array $workspaces) {
		$foundNodes = array();

		$minimalPositionByIdentifier = array();
		/** @var $node NodeData */
		foreach ($nodes as $node) {

			// Find the position of the workspace, a smaller value means more priority
			$workspacePosition = array_search($node->getWorkspace(), $workspaces);

			$uniqueNodeDataIdentity = $node->getIdentifier() . '|' . $node->getDimensionsHash();
			if (!isset($minimalPositionByIdentifier[$uniqueNodeDataIdentity]) || $workspacePosition < $minimalPositionByIdentifier[$uniqueNodeDataIdentity]) {
				$foundNodes[$uniqueNodeDataIdentity] = $node;
				$minimalPositionByIdentifier[$uniqueNodeDataIdentity] = $workspacePosition;
			}
		}

		return $foundNodes;
	}

	/**
	 * Find all NodeData objects inside a given workspace sorted by path to be used
	 * in publishing. The order makes sure that parent nodes are published first.
	 *
	 * Shadow nodes are excluded, because they will be published when publishing the moved node.
	 *
	 * @param Workspace $workspace
	 * @return array<NodeData>
	 */
	public function findByWorkspace(Workspace $workspace) {
		/** @var QueryBuilder $queryBuilder */
		$queryBuilder = $this->entityManager->createQueryBuilder();

		$queryBuilder->select('n')
			->from('TYPO3\TYPO3CR\Domain\Model\NodeData', 'n')
			->where('n.workspace = :workspace')
			->andWhere('n.movedTo IS NULL OR n.removed = :removed')
			->orderBy('n.path', 'ASC')
			->setParameter('workspace', $workspace)
			->setParameter('removed', FALSE, \PDO::PARAM_BOOL);
		return $queryBuilder->getQuery()->getResult();
	}

	/**
	 * Find out if the given path exists anywhere in the CR. (internal)
	 * If you need this functionality use \TYPO3\TYPO3CR\Domain\Service\NodeService::nodePathExistsInAnyContext()
	 *
	 * @param string $nodePath
	 * @return boolean
	 */
	public function pathExists($nodePath) {
		$result = NULL;

		/** @var QueryBuilder $queryBuilder */
		$queryBuilder = $this->entityManager->createQueryBuilder();

		$this->securityContext->withoutAuthorizationChecks(function () use ($nodePath, $queryBuilder, &$result) {
			$queryBuilder->select('n.identifier')
				->from('TYPO3\TYPO3CR\Domain\Model\NodeData', 'n')
				->where('n.pathHash = :pathHash')
				->setParameter('pathHash', md5($nodePath));
			$result = (count($queryBuilder->getQuery()->getResult()) > 0 ? TRUE : FALSE);
		});

		return $result;
	}

	/**
	 * Find all node data in a path matching the given workspace hierarchy
	 *
	 * Internal method, used by Node::setPath
	 *
	 * @param string $path
	 * @param Workspace $workspace
	 * @param boolean $includeRemovedNodes Should removed nodes be included in the result (defaults to FALSE)
	 * @param boolean $recursive
	 * @return array<NodeData> Node data reduced by workspace but with all existing content dimension variants, includes removed nodes
	 */
	public function findByPathWithoutReduce($path, Workspace $workspace, $includeRemovedNodes = FALSE, $recursive = FALSE) {
		$workspaces = array();
		while ($workspace !== NULL) {
			$workspaces[] = $workspace;
			$workspace = $workspace->getBaseWorkspace();
		}

		$queryBuilder = $this->createQueryBuilder($workspaces);
		$this->addPathConstraintToQueryBuilder($queryBuilder, $path, $recursive);

		$query = $queryBuilder->getQuery();
		$foundNodes = $query->getResult();

		// Consider materialized, but not yet persisted nodes
		foreach ($this->addedNodes as $addedNode) {
			if ($addedNode->getPath() === $path) {
				$foundNodes[] = $addedNode;
			}
		}

		$foundNodes = $this->reduceNodeVariantsByWorkspaces($foundNodes, $workspaces);

		if ($includeRemovedNodes === FALSE) {
			$foundNodes = $this->filterRemovedNodes($foundNodes, FALSE);
		}

		return $foundNodes;
	}

	/**
	 * Searches for possible relations to the given entity identifier in NodeData.
	 * Will return all possible NodeData objects that contain this identifier.
	 *
	 * Note: This is an internal method that is likely to be replaced in the future.
	 *
	 * $objectTypeMap = array(
	 *    'TYPO3\Media\Domain\Model\Asset' => '',
	 *    'TYPO3\Media\Domain\Model\ImageVariant' => 'originalImage'
	 * )
	 *
	 * @param string $identifier Persistence object identifier for which to find relations
	 * @param array $objectTypeMap array where keys are object names and value is a possible sub object property path on which the object with the identifier is situated
	 * @return array<NodeData>
	 */
	public function findByRelationWithGivenPersistenceIdentifierAndObjectTypeMap($identifier, array $objectTypeMap) {
		$resultSet = array();

		// TODO: This is dirty, but the best way to detect entity relations. When we change the storage type from serialized to something better we need to adapt this.
		$query = $this->createQuery();
		$possibleNodeData = $query->matching(
			$query->logicalOr(
				$query->like('properties', '%Persistence_Object_Identifier";s:36:"' . $identifier . '%', TRUE),
				$query->like('properties', '%__identifier";s:36:"' . $identifier . '%', TRUE)
			)
		)->execute()->toArray();

		/** @var NodeData $nodeData */
		foreach ($possibleNodeData as $nodeData) {
			$nodeType = $nodeData->getNodeType();
			$addToResultSet = FALSE;

			foreach ($this->getPropertiesContainingSpecifiedTypes($nodeType, array_keys($objectTypeMap)) as $propertyName => $propertyType) {
				if (isset($objectTypeMap[$propertyType['type']])) {
					if ($this->matchesSingleObjectProperty($nodeData, $propertyName, $identifier, $propertyType['type'], $objectTypeMap[$propertyType['type']])) {
						$addToResultSet = TRUE;
					}
				} elseif (isset($objectTypeMap[$propertyType['elementType']])) {
					if ($this->matchesCollectionObjectProperty($nodeData, $propertyName, $identifier, $propertyType['elementType'], $objectTypeMap[$propertyType['elementType']])) {
						$addToResultSet = TRUE;
					}
				}
			}
			if ($addToResultSet) {
				$resultSet[] = $nodeData;
			}
		}

		return $resultSet;
	}

	/**
	 * Returns an array of properties that either directly or in a collection contain one of the given types.
	 *
	 * @param \TYPO3\TYPO3CR\Domain\Model\NodeType $nodeType
	 * @param array $typeNames Allowed types
	 * @return array<array> Array with key being the propertyName and value an array as given by \TYPO3\Flow\Utility\TypeHandling::parseType()
	 */
	protected function getPropertiesContainingSpecifiedTypes(NodeType $nodeType, array $typeNames) {
		$propertiesWithTypes = array();
		foreach ($nodeType->getProperties() as $propertyName => $propertyConfiguration) {
			$rawPropertyType = 'string';
			if (isset($propertyConfiguration['type'])) {
				$rawPropertyType = $propertyConfiguration['type'];
			}
			try {
				$parsedPropertyType = TypeHandling::parseType($rawPropertyType);
			} catch (\Exception $e) {
				// The property type was not a valid PHP type, we just try the raw property then.
				$parsedPropertyType = array(
					'type' => $rawPropertyType,
					'elementType' => NULL
				);
			}
			if (in_array($parsedPropertyType['type'], $typeNames) || in_array($parsedPropertyType['elementType'], $typeNames)) {
				$propertiesWithTypes[$propertyName] = $parsedPropertyType;
			}
		}

		return $propertiesWithTypes;
	}

	/**
	 * Checks if for the given NodeData object the property specified by $propertyName contains an object of type
	 * $objectType or, if $subObjectPath tells that, a sub object, with the specified persistence object identifier exists.
	 *
	 * @param NodeData $nodeData Node data object
	 * @param string $propertyName Name of the property
	 * @param string $identifier Persistence object identifier
	 * @param string $objectType Object type
	 * @param string $subObjectPath Optional sub patch
	 * @return boolean
	 */
	protected function matchesSingleObjectProperty(NodeData $nodeData, $propertyName, $identifier, $objectType, $subObjectPath = '') {
		$possibleObject = $nodeData->getProperty($propertyName);
		return $this->isGivenObjectOrSubObjectMatchingIdentifierAndObjectType($possibleObject, $identifier, $objectType, $subObjectPath);
	}

	/**
	 * Checks if for the given NodeData object the property specified by $propertyName contains a collection which
	 * contains an object of type $objectType or, if $subObjectPath tells that, a sub object, with the specified
	 * persistence object identifier exists.
	 *
	 * @param NodeData $nodeData Node data object
	 * @param string $propertyName Name of the property
	 * @param string $identifier Persistence object identifier
	 * @param string $objectType Object type
	 * @param string $subObjectPath Optional sub patch
	 * @return boolean
	 */
	protected function matchesCollectionObjectProperty($nodeData, $propertyName, $identifier, $objectType, $subObjectPath = '') {
		$possibleCollection = $nodeData->getProperty($propertyName);

		if (is_array($possibleCollection) || $possibleCollection instanceof \Traversable) {
			foreach ($possibleCollection as $possibleObject) {
				if ($this->isGivenObjectOrSubObjectMatchingIdentifierAndObjectType($possibleObject, $identifier, $objectType, $subObjectPath)) {
					return TRUE;
				}
			}
		}

		return FALSE;
	}

	/**
	 * Checks if the given object has the specified persistence object identifier and is of the specified type
	 *
	 * @param object $object The object to check
	 * @param string $identifier The object persistence identifier
	 * @param string $objectType The object type to match
	 * @param string $subObjectPath Optional sub path
	 * @return boolean
	 */
	protected function isGivenObjectOrSubObjectMatchingIdentifierAndObjectType($object, $identifier, $objectType, $subObjectPath = '') {
		if (!is_object($object) || !$object instanceof $objectType) {
			return FALSE;
		}

		if ($subObjectPath !== '') {
			$object = ObjectAccess::getPropertyPath($object, $subObjectPath);
		}
		if (!$this->persistenceManager->getIdentifierByObject($object) === $identifier) {
			return FALSE;
		}

		return TRUE;
	}

	/**
	 * Remove all nodes below a given path. Does not care about workspaces and dimensions.
	 *
	 * @param string $path Starting point path underneath all nodes are to be removed.
	 * @return void
	 */
	public function removeAllInPath($path) {
		$query = $this->entityManager->createQuery('DELETE FROM TYPO3\TYPO3CR\Domain\Model\NodeData n WHERE n.path LIKE :path');
		$query->setParameter('path', $path . '/%');
		$query->execute();
	}

	/**
	 * Test if a given NodeData is in the set of removed node data objects
	 *
	 * @param NodeData $nodeData
	 * @return boolean TRUE If the NodeData was marked for removal
	 */
	public function isInRemovedNodes(NodeData $nodeData) {
		return $this->removedNodes->contains($nodeData);
	}

	/**
	 *
	 * @param array $workspaces
	 * @return QueryBuilder
	 */
	protected function createQueryBuilder(array $workspaces) {
		/** @var QueryBuilder $queryBuilder */
		$queryBuilder = $this->entityManager->createQueryBuilder();

		$queryBuilder->select('n')
			->from('TYPO3\TYPO3CR\Domain\Model\NodeData', 'n')
			->where('n.workspace IN (:workspaces)')
			->setParameter('workspaces', $workspaces);

		return $queryBuilder;
	}

	/**
	 * @param QueryBuilder $queryBuilder
	 * @param string $parentPath
	 * @param boolean $recursive
	 * @return void
	 */
	protected function addParentPathConstraintToQueryBuilder(QueryBuilder $queryBuilder, $parentPath, $recursive = FALSE) {
		if (!$recursive) {
			$queryBuilder->andWhere('n.parentPathHash = :parentPathHash')
				->setParameter('parentPathHash', md5($parentPath));
		} else {
			$queryBuilder->andWhere('n.parentPath LIKE :parentPath')
				->setParameter('parentPath', $parentPath . '%');
		}
	}

	/**
	 * @param QueryBuilder $queryBuilder
	 * @param string $path
	 * @param boolean $recursive
	 * @return void
	 */
	protected function addPathConstraintToQueryBuilder(QueryBuilder $queryBuilder, $path, $recursive = FALSE) {
		if (!$recursive) {
			$queryBuilder->andWhere('n.pathHash = :pathHash')
				->setParameter('pathHash', md5($path));
		} else {
			$queryBuilder->andWhere('n.path LIKE :path')
				->setParameter('path', $path . '%');
		}
	}

	/**
	 * @param QueryBuilder $queryBuilder
	 * @param string $identifier
	 * @return void
	 */
	protected function addIdentifierConstraintToQueryBuilder(QueryBuilder $queryBuilder, $identifier) {
		$queryBuilder->andWhere('n.identifier = :identifier')
			->setParameter('identifier', $identifier);
	}
}
