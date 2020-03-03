<?php

/*
 * Copyright 2011 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\TranslationBundle\Twig;

use JMS\TranslationBundle\Exception\RuntimeException;
use JMS\TranslationBundle\Twig\Node\Transchoice;
use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\Binary\EqualBinary;
use Twig\Node\Expression\ConditionalExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Node;
use Twig\NodeVisitor\AbstractNodeVisitor;

/**
 * Applies the value of the "desc" filter if the "trans" filter has no
 * translations.
 *
 * This is only active in your development environment.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class DefaultApplyingNodeVisitor extends AbstractNodeVisitor
{
    /**
     * @var bool
     */
    private $enabled = true;

    /**
     * @param $bool
     */
    public function setEnabled($bool)
    {
        $this->enabled = (bool) $bool;
    }

    /**
     * @return Node
     */
    public function doEnterNode(Node $node, Environment $env)
    {
        if (!$this->enabled) {
            return $node;
        }

        if ($node instanceof FilterExpression
            && 'desc' === $node->getNode('filter')->getAttribute('value')) {
            $transNode = $node->getNode('node');
            while (
                $transNode instanceof FilterExpression
                && 'trans' !== $transNode->getNode('filter')->getAttribute('value')
            ) {
                $transNode = $transNode->getNode('node');
            }

            if (!$transNode instanceof FilterExpression) {
                throw new RuntimeException(sprintf('The "desc" filter must be applied after a "trans" filter.'));
            }

            $wrappingNode = $node->getNode('node');
            $testNode = clone $wrappingNode;
            $defaultNode = $node->getNode('arguments')->getNode(0);

            // if the |trans filter with conditional text is used, delegate the call to the TranslationExtension
            // so that we can catch a possible exception when the default translation has not yet
            // been extracted
            if ($this->isTransNodeConditional($transNode)) {
                $transchoiceArguments = new ArrayExpression(array(), $transNode->getTemplateLine());
                $transchoiceArguments->addElement($wrappingNode->getNode('node'));
                $transchoiceArguments->addElement($defaultNode);
                foreach ($wrappingNode->getNode('arguments') as $arg) {
                    $transchoiceArguments->addElement($arg);
                }

                $transchoiceNode = new Transchoice($transchoiceArguments, $transNode->getTemplateLine());
                $node->setNode('node', $transchoiceNode);

                return $node;
            }

            // if the |trans filter has replacements parameters
            // (e.g. |trans({'%foo%': 'bar'}))
            if ($wrappingNode->getNode('arguments')->hasNode(0)) {
                $lineno =  $wrappingNode->getTemplateLine();

                // remove the replacements from the test node
                $testNode->setNode('arguments', clone $testNode->getNode('arguments'));
                $testNode->getNode('arguments')->setNode(0, new ArrayExpression(array(), $lineno));

                // wrap the default node in a |replace filter
                $defaultNode = new FilterExpression(
                    clone $node->getNode('arguments')->getNode(0),
                    new ConstantExpression('replace', $lineno),
                    new Node(array(
                        clone $wrappingNode->getNode('arguments')->getNode(0)
                    )),
                    $lineno
                );
            }

            $condition = new ConditionalExpression(
                new EqualBinary($testNode, $transNode->getNode('node'), $wrappingNode->getTemplateLine()),
                $defaultNode,
                clone $wrappingNode,
                $wrappingNode->getTemplateLine()
            );
            $node->setNode('node', $condition);
        }

        return $node;
    }

    private function isTransNodeConditional(FilterExpression $node)
    {
        if ('trans' !== $node->getNode('filter')->getAttribute('value')) {
            return false;
        }

        $transArgumentNode = $node->getNode('arguments');
        foreach ($transArgumentNode->getIterator() as $transFilterArgument) {
            if (!$transFilterArgument instanceof ArrayExpression) {
                continue;
            }

            foreach ($transFilterArgument->getIterator() as $translationParameterNode) {
                if (!$translationParameterNode->hasAttribute('value')) {
                    continue;
                }

                // @todo Is there any better way to check if this is a conditional form?
                if ($translationParameterNode->getAttribute('value') === '%count%') {
                    return true;
                }
            }


        }

        return false;
    }

    /**
     * @param Node $node
     * @param Environment $env
     * @return Node
     */
    public function doLeaveNode(Node $node, Environment $env)
    {
        return $node;
    }

    /**
     * @return int
     */
    public function getPriority()
    {
        return -2;
    }
}
