<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Model\Payflow\Service\Response\Validator;

use Magento\Framework\DataObject;
use Magento\Paypal\Model\Payflow\Service\Response\ValidatorInterface;
use Magento\Paypal\Model\Payflow\Transparent;

/**
 * Class AVSResponse
 */
class AVSResponse implements ValidatorInterface
{
    /**
     * AVS address responses are for advice only. This
     * process does not affect the outcome of the
     * authorization.
     */
    const AVSADDR = 'avsaddr';

    /**
     * AVS ZIP code responses are for advice only. This
     * process does not affect the outcome of the
     * authorization.
     */
    const AVSZIP = 'avszip';

    /**
     * International AVS address responses are for advice
     * only. This value does not affect the outcome of the
     * transaction.
     * Indicates whether AVS response is international (Y),
     * US (N), or cannot be determined (X). Client version
     * 3.06 or later is required.
     * @deprecated
     * @see \Magento\Paypal\Model\Payflow\Service\Response\Validator\IAVSResponse
     */
    const IAVS = 'iavs';

    /**#@+ Values of the response */
    const RESPONSE_YES = 'y';

    const RESPONSE_NO = 'n';

    const RESPONSE_NOT_SUPPORTED = 'x';
    /**#@-*/

    /**#@+ Values of the validation settings payments */
    const CONFIG_ON = 1;

    const CONFIG_OFF = 0;
    /**#@-*/

    /**#@-*/
    protected $avsCheck = [
        'avsaddr' => 'avs_street',
        'avszip' => 'avs_zip',
    ];

    /**
     * @var array
     */
    protected $errorsMessages = [
        'avs_street' => 'AVS address does not match.',
        'avs_zip' => 'AVS zip does not match.',
    ];

    /**
     * Validate data
     *
     * @param DataObject|Object $response
     * @param Transparent $transparentModel
     * @return bool
     */
    public function validate(DataObject $response, Transparent $transparentModel)
    {
        $config = $transparentModel->getConfig();
        foreach ($this->avsCheck as $fieldName => $settingName) {
            if ($config->getValue($settingName) == static::CONFIG_ON
                && strtolower((string) $response->getData($fieldName)) === static::RESPONSE_NO
            ) {
                $response->setRespmsg($this->errorsMessages[$settingName]);
                return false;
            }
        }

        return true;
    }
}
