<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Search\Test\Unit\Adapter\Mysql\Query\Builder;

use Magento\Framework\DB\Select;
use Magento\Framework\Search\Request\Query\BoolExpression;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use \PHPUnit\Framework\MockObject\MockObject as MockObject;

class MatchTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\ScoreBuilder|MockObject
     */
    private $scoreBuilder;

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\Field\ResolverInterface|MockObject
     */
    private $resolver;

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\Query\Builder\Match
     */
    private $match;

    /**
     * @var \Magento\Framework\DB\Helper\Mysql\Fulltext|MockObject
     */
    private $fulltextHelper;

    /**
     * @var \Magento\Framework\Search\Adapter\Preprocessor\PreprocessorInterface|MockObject
     */
    private $preprocessor;

    protected function setUp(): void
    {
        $helper = new ObjectManager($this);

        $this->scoreBuilder = $this->getMockBuilder(\Magento\Framework\Search\Adapter\Mysql\ScoreBuilder::class)
            ->setMethods(['addCondition'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->resolver = $this->getMockBuilder(\Magento\Framework\Search\Adapter\Mysql\Field\ResolverInterface::class)
            ->setMethods(['resolve'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->fulltextHelper = $this->getMockBuilder(\Magento\Framework\DB\Helper\Mysql\Fulltext::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->preprocessor = $this->getMockBuilder(\Magento\Search\Adapter\Query\Preprocessor\Synonyms::class)
            ->setMethods(['process'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->match = $helper->getObject(
            \Magento\Framework\Search\Adapter\Mysql\Query\Builder\Match::class,
            [
                'resolver' => $this->resolver,
                'fulltextHelper' => $this->fulltextHelper,
                'preprocessors' => [$this->preprocessor]
            ]
        );
    }

    public function testBuild()
    {
        /** @var Select|\PHPUnit\Framework\MockObject\MockObject $select */
        $select = $this->getMockBuilder(\Magento\Framework\DB\Select::class)
            ->setMethods(['getMatchQuery', 'match', 'where'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->preprocessor->expects($this->once())
            ->method('process')
            ->with($this->equalTo('some_value '))
            ->willReturn('some_value ');
        $this->fulltextHelper->expects($this->once())
            ->method('getMatchQuery')
            ->with($this->equalTo(['some_field' => 'some_field']), $this->equalTo('-some_value*'))
            ->willReturn('matchedQuery');
        $select->expects($this->once())
            ->method('where')
            ->with('matchedQuery')
            ->willReturnSelf();

        $this->resolver->expects($this->once())
            ->method('resolve')
            ->willReturnCallback(function ($fieldList) {
                $resolvedFields = [];
                foreach ($fieldList as $column) {
                    $field = $this->getMockBuilder(\Magento\Framework\Search\Adapter\Mysql\Field\FieldInterface::class)
                        ->disableOriginalConstructor()
                        ->getMockForAbstractClass();
                    $field->expects($this->any())
                        ->method('getColumn')
                        ->willReturn($column);
                    $resolvedFields[] = $field;
                }
                return $resolvedFields;
            });

        /** @var \Magento\Framework\Search\Request\Query\Match|\PHPUnit\Framework\MockObject\MockObject $query */
        $query = $this->getMockBuilder(\Magento\Framework\Search\Request\Query\Match::class)
            ->setMethods(['getMatches', 'getValue'])
            ->disableOriginalConstructor()
            ->getMock();
        $query->expects($this->once())
            ->method('getValue')
            ->willReturn('some_value ');
        $query->expects($this->once())
            ->method('getMatches')
            ->willReturn([['field' => 'some_field']]);

        $this->scoreBuilder->expects($this->once())
            ->method('addCondition');

        $result = $this->match->build(
            $this->scoreBuilder,
            $select,
            $query,
            BoolExpression::QUERY_CONDITION_NOT,
            [$this->preprocessor]
        );

        $this->assertEquals($select, $result);
    }
}
