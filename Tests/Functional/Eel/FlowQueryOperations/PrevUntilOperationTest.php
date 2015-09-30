<?php
namespace TYPO3\TYPO3CR\Tests\Functional\Eel\FlowQueryOperations;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "TYPO3.TYPO3CR".          *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\Eel\FlowQuery\FlowQuery;
use TYPO3\TYPO3CR\Tests\Functional\AbstractNodeTest;

/**
 * Functional test case which tests FlowQuery PrevUntilOperation
 */
class PrevUntilOperationTest extends AbstractNodeTest
{
    /**
     * @return array
     */
    public function prevUntilOperationDataProvider()
    {
        return array(
            array(
                'currentNodePaths' => array('/a/a5'),
                'subject' => '[instanceof TYPO3.TYPO3CR.Testing:NodeType]',
                'expectedNodePaths' => array('/a/a4'),
                'unexpectedNodePaths' => array('/a/a5','/a/a3','/a/a2')
            ),
            array(
                'currentNodePaths' => array('/a/a3'),
                'subject' => '[instanceof TYPO3.TYPO3CR.Testing:NodeType]',
                'expectedNodePaths' => array('/a/a2'),
                'unexpectedNodePaths' => array('a/a1')
            ),
            array(
                'currentNodePaths' => array('/a/a4'),
                'subject' => '[instanceof TYPO3.TYPO3CR.Testing:NodeType]',
                'expectedNodePaths' => array(),
                'unexpectedNodePaths' => array('/a/a2','/a/a3','/a/a5')
            ),
            array(
                'currentNodePaths' => array('/b/b4'),
                'subject' => 'b2[instanceof TYPO3.TYPO3CR.Testing:NodeType]',
                'expectedNodePaths' => array('/b/b3'),
                'unexpectedNodePaths' => array('/b/b4','/a','/a/a1')
            ),
            array(
                'currentNodePaths' => array('/a/a5'),
                'subject' => '',
                'expectedNodePaths' => array('/a/a1','/a/a2','/a/a3','/a/a4'),
                'unexpectedNodePaths' => array('/a/a5','/b','/b1')
            )
        );
    }

    /**
     * Tests on a tree:
     *
     * a
     *   a1 (testNodeType)
     *   a2
     *   a3 (testNodeType)
     *   a4
     *   a5
     * b
     *   b1
     *   b2 (testNodeType3)
     *   b3
     *   b4
     *
     * @test
     * @dataProvider prevUntilOperationDataProvider()
     */
    public function prevUntilOperationTests(array $currentNodePaths, $subject, array $expectedNodePaths, array $unexpectedNodePaths)
    {
        $nodeTypeManager = $this->objectManager->get('TYPO3\TYPO3CR\Domain\Service\NodeTypeManager');
        $testNodeType = $nodeTypeManager->getNodeType('TYPO3.TYPO3CR.Testing:NodeType');


        $rootNode = $this->node->getNode('/');
        $nodeA = $rootNode->createNode('a');
        $nodeA->createNode('a1', $testNodeType);
        $nodeA->createNode('a2');
        $nodeA->createNode('a3', $testNodeType);
        $nodeA->createNode('a4');
        $nodeA->createNode('a5');
        $nodeB = $rootNode->createNode('b');
        $nodeB->createNode('b1');
        $nodeB->createNode('b2', $testNodeType);
        $nodeB->createNode('b3');
        $nodeB->createNode('b4');


        $currentNodes = array();
        foreach ($currentNodePaths as $currentNodePath) {
            $currentNodes[] = $rootNode->getNode($currentNodePath);
        }

        if (is_array($subject)) {
            $subjectNodes = array();
            foreach ($subject as $subjectNodePath) {
                $subjectNodes[] = $rootNode->getNode($subjectNodePath);
            }
            $subject = $subjectNodes;
        }

        $q = new FlowQuery($currentNodes);
        $result = $q->prevUntil($subject)->get();

        if ($expectedNodePaths === array() && $unexpectedNodePaths === array()) {
            $this->assertEmpty($result);
        } else {
            foreach ($expectedNodePaths as $expectedNodePath) {
                $expectedNode = $rootNode->getNode($expectedNodePath);
                if (!in_array($expectedNode, $result)) {
                    $this->fail(sprintf('Expected result to contain node "%s"', $expectedNodePath));
                }
            }
            foreach ($unexpectedNodePaths as $unexpectedNodePath) {
                $unexpectedNode = $rootNode->getNode($unexpectedNodePath);
                if (in_array($unexpectedNode, $result)) {
                    $this->fail(sprintf('Expected result not to contain node "%s"', $unexpectedNodePath));
                }
            }
        }
    }
}
