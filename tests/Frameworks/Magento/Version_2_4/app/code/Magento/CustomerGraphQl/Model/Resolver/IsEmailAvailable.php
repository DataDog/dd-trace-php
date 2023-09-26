<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CustomerGraphQl\Model\Resolver;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Validator\EmailAddress as EmailValidator;

/**
 * Is Customer Email Available
 */
class IsEmailAvailable implements ResolverInterface
{
    /**
     * @var AccountManagementInterface
     */
    private $accountManagement;

    /**
     * @var EmailValidator
     */
    private $emailValidator;

    /**
     * @param AccountManagementInterface $accountManagement
     * @param EmailValidator $emailValidator
     */
    public function __construct(
        AccountManagementInterface $accountManagement,
        EmailValidator $emailValidator
    ) {
        $this->accountManagement = $accountManagement;
        $this->emailValidator = $emailValidator;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (empty($args['email'])) {
            throw new GraphQlInputException(__('Email must be specified'));
        }

        if (!$this->emailValidator->isValid($args['email'])) {
            throw new GraphQlInputException(__('Email is invalid'));
        }

        try {
            $isEmailAvailable = $this->accountManagement->isEmailAvailable($args['email']);
        } catch (LocalizedException $e) {
            throw new GraphQlInputException(__($e->getMessage()), $e);
        }

        return [
            'is_email_available' => $isEmailAvailable
        ];
    }
}
