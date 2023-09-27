<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Model\MessageGenerators;

use Magento\Framework\ObjectManagerInterface;
use Magento\Signifyd\Model\MessageGeneratorInterface;

/**
 * Creates instance of message generator based on received type of message.
 *
 * @deprecated 100.3.5 Starting from Magento 2.3.5 Signifyd core integration is deprecated in favor of
 * official Signifyd integration available on the marketplace
 */
class GeneratorFactory
{
    /**
     * Type of message for Signifyd case creation.
     * @var string
     */
    private static $caseCreation = 'cases/creation';

    /**
     * Type of message for Signifyd case re-scoring.
     * @var string
     */
    private static $caseRescore = 'cases/rescore';

    /**
     * Type of message for Signifyd case reviewing
     * @var string
     */
    private static $caseReview = 'cases/review';

    /**
     * Type of message of Signifyd guarantee completion
     * @var string
     */
    private static $guaranteeCompletion = 'guarantees/completion';

    /**
     * Type of message of Signifyd guarantee creation
     * @var string
     */
    private static $guaranteeCreation = 'guarantees/creation';

    /**
     * Type of message of Signifyd guarantee canceling
     * @var string
     */
    private static $guaranteeCancel = 'guarantees/cancel';

    /**
     * UpdatingServiceFactory constructor.
     *
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Creates instance of message generator.
     * Throws exception if type of message generator does not have implementations.
     *
     * @param string $type
     * @return GeneratorInterface
     * @throws \InvalidArgumentException
     */
    public function create($type)
    {
        $className = PatternGenerator::class;
        switch ($type) {
            case self::$caseCreation:
                $classConfig = [
                    'template' => 'Signifyd Case %1 has been created for order.',
                    'requiredParams' => ['caseId']
                ];
                break;
            case self::$caseRescore:
                $classConfig = [];
                $className = CaseRescore::class;
                break;
            case self::$caseReview:
                $classConfig = [
                    'template' => 'Case Update: Case Review was completed. Review Deposition is %1.',
                    'requiredParams' => ['reviewDisposition']
                ];
                break;
            case self::$guaranteeCompletion:
                $classConfig = [
                    'template' => 'Case Update: Guarantee Disposition is %1.',
                    'requiredParams' => ['guaranteeDisposition']
                ];
                break;
            case self::$guaranteeCreation:
                $classConfig = [
                    'template' => 'Case Update: Case is submitted for guarantee.'
                ];
                break;
            case self::$guaranteeCancel:
                $classConfig = [
                    'template' => 'Case Update: Case guarantee has been cancelled.'
                ];
                break;
            default:
                throw new \InvalidArgumentException('Specified message type does not supported.');
        }

        return $this->objectManager->create($className, $classConfig);
    }
}
