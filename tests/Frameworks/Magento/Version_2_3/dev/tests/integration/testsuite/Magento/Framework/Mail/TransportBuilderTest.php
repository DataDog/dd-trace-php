<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Mail;

use Magento\Email\Model\BackendTemplate;
use Magento\Email\Model\Template;
use Magento\Framework\App\TemplateTypesInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Class EmailMessageTest
 */
class TransportBuilderTest extends TestCase
{
    /**
     * @var ObjectManagerInterface
     */
    private $di;

    /**
     * @var TransportBuilder
     */
    protected $builder;

    /**
     * @var Template
     */
    protected $template;

    protected function setUp(): void
    {
        $this->di = Bootstrap::getObjectManager();
        $this->builder = $this->di->get(TransportBuilder::class);
        $this->template = $this->di->get(Template::class);
    }

    /**
     * @magentoDataFixture Magento/Email/Model/_files/email_template.php
     * @magentoDbIsolation enabled
     *
     * @param string|array $email
     * @dataProvider emailDataProvider
     * @throws LocalizedException
     */
    public function testAddToEmail($email)
    {
        $template = $this->template->load('email_exception_fixture', 'template_code');
        $templateId = $template->getId();

        switch ($template->getType()) {
            case TemplateTypesInterface::TYPE_TEXT:
                $templateType = MimeInterface::TYPE_TEXT;
                break;

            case TemplateTypesInterface::TYPE_HTML:
                $templateType = MimeInterface::TYPE_HTML;
                break;

            default:
                $templateType = '';
                $this->fail('Unsupported Mime Type');
        }

        $this->builder->setTemplateModel(BackendTemplate::class);

        $vars = ['reason' => 'Reason', 'customer' => 'Customer'];
        $options = ['area' => 'frontend', 'store' => 1];
        $this->builder->setTemplateIdentifier($templateId)->setTemplateVars($vars)->setTemplateOptions($options);

        $this->builder->addTo($email);

        /** @var EmailMessage $emailMessage */
        $emailMessage = $this->builder->getTransport()->getMessage();

        $this->assertStringContainsString($templateType, $emailMessage->getHeaders()['Content-Type']);

        $addresses = $emailMessage->getTo();

        $emails = [];
        /** @var Address $toAddress */
        foreach ($addresses as $address) {
            $emails[] = $address->getEmail();
        }

        if (is_string($email)) {
            $this->assertCount(1, $emails);
            $this->assertEquals($email, $emails[0]);
        } else {
            $this->assertEquals($email, $emails);
        }
    }

    /**
     * @return array
     */
    public function emailDataProvider(): array
    {
        return [
            [
                'billy.everything@someserver.com',
            ],
            [
                [
                    'billy.everything@someserver.com',
                    'john.doe@someserver.com',
                ]
            ]
        ];
    }
}
