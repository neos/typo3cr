<?php
namespace TYPO3\TYPO3CR\Tests\Functional\Domain\Repository;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "TYPO3CR".               *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */
use TYPO3\Flow\Tests\Functional\Persistence\Fixtures\Image;
use TYPO3\Flow\Tests\FunctionalTestCase;
use TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository;
use TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface;
use TYPO3\TYPO3CR\Domain\Service\NodeTypeManager;
use TYPO3\TYPO3CR\Tests\Functional\Domain\Fixtures\TestObjectForSerialization;

/**
 * Functional test case.
 */
class NodeDataRepositoryTest extends FunctionalTestCase
{
    /**
     * @var \TYPO3\TYPO3CR\Domain\Service\Context
     */
    protected $context;

    /**
     * @var boolean
     */
    protected static $testablePersistenceEnabled = true;

    /**
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @return void
     */
    public function setUp()
    {
        parent::setUp();
        $this->nodeTypeManager = $this->objectManager->get('TYPO3\TYPO3CR\Domain\Service\NodeTypeManager');
        $this->contextFactory = $this->objectManager->get('TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface');
        $this->context = $this->contextFactory->create(array('workspaceName' => 'live'));
        $this->nodeDataRepository = $this->objectManager->get('TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository');
    }

    /**
     * @return void
     */
    public function tearDown()
    {
        parent::tearDown();
        $this->inject($this->contextFactory, 'contextInstances', array());
    }

    /**
     * @test
     */
    public function findByRelationWithGivenPersistenceIdentifierAndObjectTypeMapFindsExistingNodeWithMatchingEntityProperty()
    {
        $rootNode = $this->context->getRootNode();
        $newNode = $rootNode->createNode('test', $this->nodeTypeManager->getNodeType('TYPO3.TYPO3CR.Testing:NodeTypeWithEntities'));

        $testImage = new Image();
        $this->persistenceManager->add($testImage);

        $newNode->setProperty('image', $testImage);

        $this->persistenceManager->persistAll();

        $result = $this->nodeDataRepository->findByRelationWithGivenPersistenceIdentifierAndObjectTypeMap($this->persistenceManager->getIdentifierByObject($testImage), array(
            'TYPO3\Flow\Tests\Functional\Persistence\Fixtures\Image' => ''
        ));

        $this->assertCount(1, $result);
    }

    /**
     * @test
     */
    public function findByRelationWithGivenPersistenceIdentifierAndObjectTypeMapFindsExistingNodeWithMatchingNestedEntityProperty()
    {
        $persistenceDriver = $this->objectManager->get('TYPO3\Flow\Configuration\ConfigurationManager')->getConfiguration(\TYPO3\Flow\Configuration\ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'TYPO3.Flow.persistence.backendOptions.driver');
        if ($persistenceDriver === 'pdo_sqlite') {
            $this->markTestSkipped('This test fails on SQLite, thus it is skipped.');
        }

        $rootNode = $this->context->getRootNode();
        $newNode = $rootNode->createNode('test', $this->nodeTypeManager->getNodeType('TYPO3.TYPO3CR.Testing:NodeTypeWithEntities'));

        $testImage = new Image();
        $this->persistenceManager->add($testImage);

        $imageWrapper = new TestObjectForSerialization($testImage);

        $newNode->setProperty('wrappedImage', $imageWrapper);

        $this->persistenceManager->persistAll();

        $result = $this->nodeDataRepository->findByRelationWithGivenPersistenceIdentifierAndObjectTypeMap($this->persistenceManager->getIdentifierByObject($testImage), array(
            'TYPO3\TYPO3CR\Tests\Functional\Domain\Fixtures\TestObjectForSerialization' => 'value'
        ));

        $this->assertCount(1, $result);
    }
}
