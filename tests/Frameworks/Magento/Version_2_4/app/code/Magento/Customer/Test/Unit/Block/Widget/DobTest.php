<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Customer\Test\Unit\Block\Widget;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\Data\AttributeMetadataInterface;
use Magento\Customer\Api\Data\ValidationRuleInterface;
use Magento\Customer\Block\Widget\Dob;
use Magento\Customer\Helper\Address;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Data\Form\FilterFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Json\EncoderInterface;
use Magento\Framework\Locale\Resolver;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\Intl\DateFormatterFactory;
use Magento\Framework\Stdlib\DateTime\Timezone;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Element\Html\Date;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zend_Cache_Backend_BlackHole;
use Zend_Cache_Core;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DobTest extends TestCase
{
    /** Constants used in the unit tests */
    const MIN_DATE = '01/01/2010';

    const MAX_DATE = '01/01/2020';

    const DATE = '01/01/2014';

    const DAY = '01';

    // Value of date('d', strtotime(self::DATE))
    const MONTH = '01';

    // Value of date('m', strtotime(self::DATE))
    const YEAR = '2014';

    // Value of date('Y', strtotime(self::DATE))
    const DATE_FORMAT = 'M/dd/y';

    /** Constants used by Dob::setDateInput($code, $html) */
    const DAY_HTML =
        '<div><label for="day"><span>d</span></label><input type="text" id="day" name="Day" value="1"></div>';

    const MONTH_HTML =
        '<div><label for="month"><span>M</span></label><input type="text" id="month" name="Month" value="jan"></div>';

    const YEAR_HTML =
        '<div><label for="year"><span>yy</span></label><input type="text" id="year" name="Year" value="14"></div>';

    /** @var MockObject|AttributeMetadataInterface */
    protected $attribute;

    /** @var Dob */
    protected $_block;

    /** @var MockObject|CustomerMetadataInterface */
    protected $customerMetadata;

    /**
     * @var FilterFactory|MockObject
     */
    protected $filterFactory;

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @var Context
     */
    private $context;
    /**
     * @var string
     */
    private $_locale;

    /**
     * @var EncoderInterface
     */
    private $encoder;

    /**
     * @var ResolverInterface
     */
    private $localeResolver;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $zendCacheCore = new Zend_Cache_Core();
        $zendCacheCore->setBackend(new Zend_Cache_Backend_BlackHole());

        $frontendCache = $this->getMockForAbstractClass(
            FrontendInterface::class,
            [],
            '',
            false
        );
        $frontendCache->expects($this->any())->method('getLowLevelFrontend')->willReturn($zendCacheCore);
        $cache = $this->getMockForAbstractClass(CacheInterface::class);
        $cache->expects($this->any())->method('getFrontend')->willReturn($frontendCache);

        $objectManager = new ObjectManager($this);
        $this->localeResolver = $this->getMockForAbstractClass(ResolverInterface::class);
        $this->localeResolver->expects($this->any())
            ->method('getLocale')
            ->willReturnCallback(
                function () {
                    return $this->_locale;
                }
            );
        $localeResolver = $this->localeResolver;
        $timezone = $objectManager->getObject(
            Timezone::class,
            ['localeResolver' => $localeResolver, 'dateFormatterFactory' => new DateFormatterFactory()]
        );

        $this->_locale = Resolver::DEFAULT_LOCALE;
        $this->context = $this->createMock(Context::class);
        $this->context->expects($this->any())->method('getLocaleDate')->willReturn($timezone);
        $this->escaper = $this->getMockBuilder(Escaper::class)
            ->disableOriginalConstructor()
            ->setMethods(['escapeHtml'])
            ->getMock();
        $this->context->expects($this->any())->method('getEscaper')->willReturn($this->escaper);

        $this->attribute = $this->getMockBuilder(AttributeMetadataInterface::class)
            ->getMockForAbstractClass();
        $this->attribute
            ->expects($this->any())
            ->method('getInputFilter')
            ->willReturn('date');
        $this->customerMetadata = $this->getMockBuilder(CustomerMetadataInterface::class)
            ->getMockForAbstractClass();
        $this->customerMetadata->expects($this->any())
            ->method('getAttributeMetadata')
            ->willReturn($this->attribute);

        $this->filterFactory = $this->createMock(FilterFactory::class);
        $this->filterFactory
            ->expects($this->any())
            ->method('create')
            ->willReturnCallback(
                function () use ($timezone, $localeResolver) {
                    return new \Magento\Framework\Data\Form\Filter\Date(
                        $timezone->getDateFormatWithLongYear(),
                        $localeResolver
                    );
                }
            );

        $this->encoder = $this->getMockForAbstractClass(EncoderInterface::class);

        $this->_block = new Dob(
            $this->context,
            $this->createMock(Address::class),
            $this->customerMetadata,
            $this->createMock(Date::class),
            $this->filterFactory,
            [],
            $this->encoder,
            $this->localeResolver
        );
    }

    /**
     * @param bool $isVisible Determines whether the 'dob' attribute is visible or enabled
     * @param bool $expectedValue The value we expect from Dob::isEnabled()
     *
     * @dataProvider isEnabledDataProvider
     */
    public function testIsEnabled($isVisible, $expectedValue)
    {
        $this->attribute->expects($this->once())->method('isVisible')->willReturn($isVisible);
        $this->assertSame($expectedValue, $this->_block->isEnabled());
    }

    /**
     * @return array
     */
    public function isEnabledDataProvider()
    {
        return [[true, true], [false, false]];
    }

    /**
     * Tests isEnabled()
     */
    public function testIsEnabledWithException()
    {
        $this->customerMetadata->expects($this->any())
            ->method('getAttributeMetadata')
            ->willThrowException(
                new NoSuchEntityException(
                    __(
                        'No such entity with %fieldName = %fieldValue',
                        ['fieldName' => 'field', 'fieldValue' => 'value']
                    )
                )
            );
        $this->assertFalse($this->_block->isEnabled());
    }

    /**
     * @param bool $isRequired Determines whether the 'dob' attribute is required
     * @param bool $expectedValue The value we expect from Dob::isRequired()
     *
     * @dataProvider isRequiredDataProvider
     */
    public function testIsRequired($isRequired, $expectedValue)
    {
        $this->attribute->expects($this->once())->method('isRequired')->willReturn($isRequired);
        $this->assertSame($expectedValue, $this->_block->isRequired());
    }

    public function testIsRequiredWithException()
    {
        $this->customerMetadata->expects($this->any())
            ->method('getAttributeMetadata')
            ->willThrowException(
                new NoSuchEntityException(
                    __(
                        'No such entity with %fieldName = %fieldValue',
                        ['fieldName' => 'field', 'fieldValue' => 'value']
                    )
                )
            );
        $this->assertFalse($this->_block->isRequired());
    }

    /**
     * @return array
     */
    public function isRequiredDataProvider()
    {
        return [[true, true], [false, false]];
    }

    /**
     * @param string|bool $date Date (e.g. '01/01/2020' or false for no date)
     * @param int|bool $expectedTime The value we expect from Dob::getTime()
     * @param string|bool $expectedDate The value we expect from Dob::getData('date')
     * @param string $locale
     * @dataProvider setDateDataProvider
     */
    public function testSetDate($date, $expectedTime, $expectedDate, $locale = Resolver::DEFAULT_LOCALE)
    {
        $this->_locale = $locale;
        $this->assertSame($this->_block, $this->_block->setDate($date));
        $this->assertSame($expectedTime, $this->_block->getTime());
        $this->assertSame($expectedDate, $this->_block->getValue());
    }

    /**
     * @return array
     */
    public function setDateDataProvider()
    {
        return [
            [false, false, false],
            ['', false, ''],
            ['12/31/1999', strtotime('1999-12-31'), '12/31/1999', 'en_US'],
            ['31-12-1999', strtotime('1999-12-31'), '12/31/1999', 'en_US'],
            ['1999-12-31', strtotime('1999-12-31'), '12/31/1999', 'en_US'],
            ['31 December 1999', strtotime('1999-12-31'), '12/31/1999', 'en_US'],
            ['12/31/1999', strtotime('1999-12-31'), '31/12/1999', 'fr_FR'],
            ['31-12-1999', strtotime('1999-12-31'), '31/12/1999', 'fr_FR'],
            ['31/12/1999', strtotime('1999-12-31'), '31/12/1999', 'fr_FR'],
            ['1999-12-31', strtotime('1999-12-31'), '31/12/1999', 'fr_FR'],
            ['31 Décembre 1999', strtotime('1999-12-31'), '31/12/1999', 'fr_FR'],
        ];
    }

    /**
     * @param string|bool $date The date (e.g. '01/01/2020' or false for no date)
     * @param string $expectedDay The value we expect from Dob::getDay()
     *
     * @dataProvider getDayDataProvider
     */
    public function testGetDay($date, $expectedDay)
    {
        $this->_block->setDate($date);
        $this->assertEquals($expectedDay, $this->_block->getDay());
    }

    /**
     * @return array
     */
    public function getDayDataProvider()
    {
        return [[self::DATE, self::DAY], [false, '']];
    }

    /**
     * @param string|bool $date The date (e.g. '01/01/2020' or false for no date)
     * @param string $expectedMonth The value we expect from Dob::getMonth()
     *
     * @dataProvider getMonthDataProvider
     */
    public function testGetMonth($date, $expectedMonth)
    {
        $this->_block->setDate($date);
        $this->assertEquals($expectedMonth, $this->_block->getMonth());
    }

    /**
     * @return array
     */
    public function getMonthDataProvider()
    {
        return [[self::DATE, self::MONTH], [false, '']];
    }

    /**
     * @param string|bool $date The date (e.g. '01/01/2020' or false for no date)
     * @param string $expectedYear The value we expect from Dob::getYear()
     *
     * @dataProvider getYearDataProvider
     */
    public function testGetYear($date, $expectedYear)
    {
        $this->_block->setDate($date);
        $this->assertEquals($expectedYear, $this->_block->getYear());
    }

    /**
     * @return array
     */
    public function getYearDataProvider()
    {
        return [[self::DATE, self::YEAR], [false, '']];
    }

    /**
     * Is used to derive the Locale that is used to determine the value of Dob::getDateFormat() for that Locale
     *
     * @param string $locale
     * @param string $expectedFormat
     * @dataProvider getDateFormatDataProvider
     */
    public function testGetDateFormat(string $locale, string $expectedFormat)
    {
        $this->_locale = $locale;
        $this->assertEquals($expectedFormat, $this->_block->getDateFormat());
    }

    /**
     * @return array
     */
    public function getDateFormatDataProvider(): array
    {
        return [
            [
                'ar_SA',
                preg_replace(
                    '/(?<!d)d(?!d)/',
                    'dd',
                    preg_replace(
                        '/[^MmDdYy\/\.\-]/',
                        '',
                        (new DateFormatterFactory())
                            ->create('ar_SA', \IntlDateFormatter::SHORT, \IntlDateFormatter::NONE)
                            ->getPattern()
                    )
                )
            ],
            [Resolver::DEFAULT_LOCALE, self::DATE_FORMAT],
        ];
    }

    /**
     * This tests the Dob::setDateInput() method. The Dob::getSortedDateInputs() uses the value of
     * Dob::getDateFormat() to derive the return value, which is equivalent to self::DATE_FORMAT.
     */
    public function testGetSortedDateInputs()
    {
        $this->_block->setDateInput('d', self::DAY_HTML);
        $this->_block->setDateInput('m', self::MONTH_HTML);
        $this->_block->setDateInput('y', self::YEAR_HTML);

        $this->assertEquals(self::MONTH_HTML . self::DAY_HTML . self::YEAR_HTML, $this->_block->getSortedDateInputs());
    }

    /**
     * This tests the Dob::setDateInput() method. The Dob::getSortedDateInputs() uses the value of
     * Dob::getDateFormat() to derive the return value, which is equivalent to self::DATE_FORMAT.
     */
    public function testGetSortedDateInputsWithoutStrippingNonInputChars()
    {
        $this->_block->setDateInput('d', self::DAY_HTML);
        $this->_block->setDateInput('m', self::MONTH_HTML);
        $this->_block->setDateInput('y', self::YEAR_HTML);

        $this->assertEquals(
            self::MONTH_HTML . '/' . self::DAY_HTML . '/' . self::YEAR_HTML,
            $this->_block->getSortedDateInputs(false)
        );
    }

    /**
     * @param array $validationRules The date Min/Max validation rules
     * @param int $expectedValue The value we expect from Dob::getMinDateRange()
     *
     * @dataProvider getMinDateRangeDataProvider
     */
    public function testGetMinDateRange($validationRules, $expectedValue)
    {
        $this->attribute->expects($this->once())
            ->method('getValidationRules')
            ->willReturn($validationRules);
        $this->assertEquals($expectedValue, $this->_block->getMinDateRange());
    }

    /**
     * @return array
     */
    public function getMinDateRangeDataProvider()
    {
        $emptyValidationRule = $this->getMockBuilder(ValidationRuleInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getName', 'getValue'])
            ->getMockForAbstractClass();

        $validationRule = $this->getMockBuilder(ValidationRuleInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getName', 'getValue'])
            ->getMockForAbstractClass();
        $validationRule->expects($this->any())
            ->method('getName')
            ->willReturn(Dob::MIN_DATE_RANGE_KEY);
        $validationRule->expects($this->any())
            ->method('getValue')
            ->willReturn(strtotime(self::MIN_DATE));

        return [
            [
                [
                    $validationRule,
                ],
                date('Y/m/d', strtotime(self::MIN_DATE)),
            ],
            [
                [
                    $emptyValidationRule,
                ],
                null
            ]
        ];
    }

    /**
     * Tests getMinDateRange()
     */
    public function testGetMinDateRangeWithException()
    {
        $this->customerMetadata->expects($this->any())
            ->method('getAttributeMetadata')
            ->willThrowException(
                new NoSuchEntityException(
                    __(
                        'No such entity with %fieldName = %fieldValue',
                        ['fieldName' => 'field', 'fieldValue' => 'value']
                    )
                )
            );
        $this->assertNull($this->_block->getMinDateRange());
    }

    /**
     * @param array $validationRules The date Min/Max validation rules
     * @param int $expectedValue The value we expect from Dob::getMaxDateRange()
     *
     * @dataProvider getMaxDateRangeDataProvider
     */
    public function testGetMaxDateRange($validationRules, $expectedValue)
    {
        $this->attribute->expects($this->once())
            ->method('getValidationRules')
            ->willReturn($validationRules);
        $this->assertEquals($expectedValue, $this->_block->getMaxDateRange());
    }

    /**
     * @return array
     */
    public function getMaxDateRangeDataProvider()
    {
        $emptyValidationRule = $this->getMockBuilder(ValidationRuleInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getName', 'getValue'])
            ->getMockForAbstractClass();

        $validationRule = $this->getMockBuilder(ValidationRuleInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getName', 'getValue'])
            ->getMockForAbstractClass();
        $validationRule->expects($this->any())
            ->method('getName')
            ->willReturn(Dob::MAX_DATE_RANGE_KEY);
        $validationRule->expects($this->any())
            ->method('getValue')
            ->willReturn(strtotime(self::MAX_DATE));
        return [
            [
                [
                    $validationRule,
                ],
                date('Y/m/d', strtotime(self::MAX_DATE)),
            ],
            [
                [
                    $emptyValidationRule,
                ],
                null
            ]
        ];
    }

    /**
     * Tests getMaxDateRange()
     */
    public function testGetMaxDateRangeWithException()
    {
        $this->customerMetadata->expects($this->any())
            ->method('getAttributeMetadata')
            ->willThrowException(
                new NoSuchEntityException(
                    __(
                        'No such entity with %fieldName = %fieldValue',
                        ['fieldName' => 'field', 'fieldValue' => 'value']
                    )
                )
            );
        $this->assertNull($this->_block->getMaxDateRange());
    }

    /**
     * Tests getHtmlExtraParams() without required options
     */
    public function testGetHtmlExtraParamsWithoutRequiredOption()
    {
        $validation = json_encode(
            [
                'validate-date' => [
                    'dateFormat' => self::DATE_FORMAT
                ],
                'validate-dob' => [
                    'dateFormat' => self::DATE_FORMAT
                ],
            ]
        );
        $this->escaper->expects($this->any())
            ->method('escapeHtml')
            ->with($validation)
            ->willReturn(
                $validation
            );

        $this->attribute->expects($this->once())
            ->method("isRequired")
            ->willReturn(false);

        $this->assertEquals(
            "data-validate=\"$validation\"",
            $this->_block->getHtmlExtraParams()
        );
    }

    /**
     * Tests getHtmlExtraParams() with required options
     */
    public function testGetHtmlExtraParamsWithRequiredOption()
    {
        $validation = json_encode(
            [
                'required' => true,
                'validate-date' => [
                    'dateFormat' => self::DATE_FORMAT
                ],
                'validate-dob' => [
                    'dateFormat' => self::DATE_FORMAT
                ],
            ]
        );
        $this->attribute->expects($this->once())
            ->method("isRequired")
            ->willReturn(true);
        $this->escaper->expects($this->any())
            ->method('escapeHtml')
            ->with($validation)
            ->willReturn(
                $validation
            );

        $this->context->expects($this->any())->method('getEscaper')->willReturn($this->escaper);

        $this->assertEquals(
            "data-validate=\"$validation\"",
            $this->_block->getHtmlExtraParams()
        );
    }

    /**
     * Tests getTranslatedCalendarConfigJson()
     *
     * @param string $locale
     * @param array $expectedArray
     * @param string $expectedJson
     * @dataProvider getTranslatedCalendarConfigJsonDataProvider
     * @return void
     */
    public function testGetTranslatedCalendarConfigJson(
        string $locale,
        array $expectedArray,
        string $expectedJson
    ): void {
        $this->_locale = $locale;

        $this->encoder->expects($this->once())
            ->method('encode')
            ->with($expectedArray)
            ->willReturn($expectedJson);

        $this->assertEquals(
            $expectedJson,
            $this->_block->getTranslatedCalendarConfigJson()
        );
    }

    /**
     * Provider for testGetTranslatedCalendarConfigJson
     *
     * @return array
     */
    public function getTranslatedCalendarConfigJsonDataProvider()
    {
        return [
            [
                'locale' => 'en_US',
                'expectedArray' => [
                    'closeText' => 'Done',
                    'prevText' => 'Prev',
                    'nextText' => 'Next',
                    'currentText' => 'Today',
                    'monthNames' => ['January', 'February', 'March', 'April', 'May', 'June',
                        'July', 'August', 'September', 'October', 'November', 'December'],
                    'monthNamesShort' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    'dayNames' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                    'dayNamesShort' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
                    'dayNamesMin' => ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
                ],
                // phpcs:disable Generic.Files.LineLength.TooLong
                'expectedJson' => '{"closeText":"Done","prevText":"Prev","nextText":"Next","currentText":"Today","monthNames":["January","February","March","April","May","June","July","August","September","October","November","December"],"monthNamesShort":["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],"dayNames":["Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"],"dayNamesShort":["Sun","Mon","Tue","Wed","Thu","Fri","Sat"],"dayNamesMin":["Su","Mo","Tu","We","Th","Fr","Sa"]}'
                // phpcs:enable Generic.Files.LineLength.TooLong
            ],
            [
                'locale' => 'de_DE',
                'expectedArray' => [
                    'closeText' => 'Done',
                    'prevText' => 'Prev',
                    'nextText' => 'Next',
                    'currentText' => 'Today',
                    'monthNames' => ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
                        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
                    'monthNamesShort' => ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni',
                        'Juli', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.'],
                    'dayNames' => ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'],
                    'dayNamesShort' => ['So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'],
                    'dayNamesMin' => ['So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'],
                ],
                // phpcs:disable Generic.Files.LineLength.TooLong
                'expectedJson' => '{"closeText":"Done","prevText":"Prev","nextText":"Next","currentText":"Today","monthNames":["Januar","Februar","M\u00e4rz","April","Mai","Juni","Juli","August","September","Oktober","November","Dezember"],"monthNamesShort":["Jan.","Feb.","M\u00e4rz","Apr.","Mai","Juni","Juli","Aug.","Sept.","Okt.","Nov.","Dez."],"dayNames":["Sonntag","Montag","Dienstag","Mittwoch","Donnerstag","Freitag","Samstag"],"dayNamesShort":["So.","Mo.","Di.","Mi.","Do.","Fr.","Sa."],"dayNamesMin":["So.","Mo.","Di.","Mi.","Do.","Fr.","Sa."]}'
                // phpcs:enable Generic.Files.LineLength.TooLong
            ],
        ];
    }
}
