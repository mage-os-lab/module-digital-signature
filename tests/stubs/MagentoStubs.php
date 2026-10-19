<?php
/**
 * Stub minimi del framework Magento per l'esecuzione standalone dei test unit
 * (senza un'installazione Magento). Riproducono SOLO il comportamento usato
 * dalle classi sotto test. Caricati dal bootstrap solo se il framework reale
 * non è disponibile.
 */
declare(strict_types=1);

// phpcs:ignoreFile

namespace Magento\Framework {
    class Phrase
    {
        /** @param array<int|string, mixed> $arguments */
        public function __construct(
            private readonly string $text,
            private readonly array $arguments = []
        ) {
        }

        public function getText(): string
        {
            return $this->text;
        }

        /** @return array<int|string, mixed> */
        public function getArguments(): array
        {
            return $this->arguments;
        }

        public function render(): string
        {
            $result = $this->text;
            foreach ($this->arguments as $key => $value) {
                $placeholder = is_int($key) ? '%' . ($key + 1) : '%' . $key;
                $result = str_replace($placeholder, (string)$value, $result);
            }

            return $result;
        }

        public function __toString(): string
        {
            return $this->render();
        }
    }
}

namespace Magento\Framework\Exception {
    class LocalizedException extends \Exception
    {
        public function __construct(
            protected readonly \Magento\Framework\Phrase $phrase,
            ?\Throwable $cause = null,
            int $code = 0
        ) {
            parent::__construct($phrase->render(), $code, $cause);
        }

        public function getRawMessage(): string
        {
            return $this->phrase->getText();
        }
    }

    class AlreadyExistsException extends LocalizedException
    {
        public function __construct(
            ?\Magento\Framework\Phrase $phrase = null,
            ?\Throwable $cause = null,
            int $code = 0
        ) {
            parent::__construct($phrase ?? new \Magento\Framework\Phrase('Unique constraint violation found'), $cause, $code);
        }
    }

    class CouldNotSaveException extends LocalizedException
    {
    }

    class FileSystemException extends LocalizedException
    {
    }

    class CouldNotDeleteException extends LocalizedException
    {
    }

    class NoSuchEntityException extends LocalizedException
    {
        public function __construct(
            ?\Magento\Framework\Phrase $phrase = null,
            ?\Throwable $cause = null,
            int $code = 0
        ) {
            parent::__construct($phrase ?? new \Magento\Framework\Phrase('No such entity.'), $cause, $code);
        }
    }
}

namespace Magento\Framework\Data {
    interface OptionSourceInterface
    {
        /** @return array<int, array<string, mixed>> */
        public function toOptionArray();
    }
}

namespace Magento\Framework\Api {
    interface SearchResultsInterface
    {
        public function getItems();

        public function setItems(array $items);

        public function setTotalCount($count);

        public function getTotalCount();
    }

    class Filter
    {
        private string $field = '';
        private string $conditionType = 'eq';
        private mixed $value = null;

        public function setField(string $field): self
        {
            $this->field = $field;

            return $this;
        }

        public function getField(): string
        {
            return $this->field;
        }

        public function setConditionType(string $conditionType): self
        {
            $this->conditionType = $conditionType;

            return $this;
        }

        public function getConditionType(): string
        {
            return $this->conditionType;
        }

        public function setValue($value): self
        {
            $this->value = $value;

            return $this;
        }

        public function getValue()
        {
            return $this->value;
        }
    }

    class FilterBuilder
    {
        private array $data = [];

        public function setField(string $field): self
        {
            $this->data['field'] = $field;

            return $this;
        }

        public function setConditionType(string $conditionType): self
        {
            $this->data['condition_type'] = $conditionType;

            return $this;
        }

        public function setValue($value): self
        {
            $this->data['value'] = $value;

            return $this;
        }

        public function create(): Filter
        {
            $filter = new Filter();
            $filter->setField($this->data['field'] ?? '');
            $filter->setConditionType($this->data['condition_type'] ?? 'eq');
            $filter->setValue($this->data['value'] ?? null);
            $this->data = [];

            return $filter;
        }
    }

    /** Same signatures as the real Magento\Framework\Api\SearchCriteriaInterface. */
    interface SearchCriteriaInterface
    {
        /** @return \Magento\Framework\Api\Search\FilterGroup[] */
        public function getFilterGroups();

        /** @return $this */
        public function setFilterGroups(?array $filterGroups = null);

        public function getSortOrders();

        /** @return $this */
        public function setSortOrders(?array $sortOrders = null);

        public function getPageSize();

        /** @return $this */
        public function setPageSize($pageSize);

        public function getCurrentPage();

        /** @return $this */
        public function setCurrentPage($currentPage);
    }
}

namespace Magento\Framework\Api\Search {
    /** A row of a UI listing data provider. */
    interface DocumentInterface
    {
        public function getId();
    }

    class FilterGroup
    {
        /** @var \Magento\Framework\Api\Filter[] */
        private array $filters = [];

        /** @return \Magento\Framework\Api\Filter[] */
        public function getFilters(): array
        {
            return $this->filters;
        }

        /** @return $this */
        public function setFilters(array $filters): self
        {
            $this->filters = $filters;

            return $this;
        }
    }

    class FilterGroupBuilder
    {
        /** @var \Magento\Framework\Api\Filter[] */
        private array $filters = [];

        public function addFilter(\Magento\Framework\Api\Filter $filter): self
        {
            $this->filters[] = $filter;

            return $this;
        }

        public function create(): FilterGroup
        {
            $group = new FilterGroup();
            $group->setFilters($this->filters);
            $this->filters = [];

            return $group;
        }
    }
}

namespace Magento\Framework\View\Element {
    interface UiComponentInterface
    {
        public function getName();

        public function getContext();
    }
}

namespace Magento\Framework\View\Element\UiComponent {
    interface ContextInterface
    {
        public function getDataProvider();
    }
}

namespace Magento\Framework\View\Element\UiComponent\DataProvider {
    interface DataProviderInterface
    {
        public function getSearchCriteria();

        public function getSearchResult();
    }
}

namespace Magento\Ui\Component\MassAction {
    use Magento\Framework\View\Element\UiComponentInterface;

    class Filter
    {
        public function getComponent()
        {
        }

        public function prepareComponent(UiComponentInterface $component)
        {
        }

        public function applySelectionOnTargetProvider()
        {
        }

        public function getCollection($collection)
        {
        }
    }
}

namespace Magento\Ui\Model\Export {
    use Magento\Framework\Api\Search\DocumentInterface;
    use Magento\Framework\View\Element\UiComponentInterface;

    /** Same signatures as the real MetadataProvider (typed parameters included). */
    class MetadataProvider
    {
        public function getFields(UiComponentInterface $component): array
        {
            return [];
        }

        public function getOptions(): array
        {
            return [];
        }

        public function getHeaders(UiComponentInterface $component): array
        {
            return [];
        }

        public function getRowData(DocumentInterface $document, $fields, $options): array
        {
            return [];
        }

        public function convertDate($document, $componentName): void
        {
        }
    }
}

namespace Magento\Framework\App {
    interface CacheInterface
    {
        public function load($identifier);

        public function save($data, $identifier, array $tags = [], $lifeTime = null);
    }
}

namespace Magento\Framework\App\Filesystem {
    class DirectoryList
    {
        public const MEDIA = 'media';
        public const VAR_DIR = 'var';
    }
}

namespace Magento\Framework\App {
    class ResourceConnection
    {
        public function getConnection($resourceName = 'default')
        {
        }

        public function getTableName($modelEntity, $connectionName = 'default')
        {
            return $modelEntity;
        }
    }
}

namespace Magento\Framework\Filesystem\Directory {
    interface ReadInterface
    {
        public function isExist($path);

        public function readFile($path);
    }
}

namespace Magento\Framework\Filesystem\Directory {
    interface WriteInterface
    {
        public function create($path);

        public function writeFile($path, $content);

        public function getAbsolutePath($path);

        public function isExist($path);

        public function delete($path);

        public function stat($path);

        public function read($path);

        public function isFile($path);
    }
}

namespace Magento\Framework {
    class Filesystem
    {
        public function getDirectoryRead($directoryCode)
        {
        }

        public function getDirectoryWrite($directoryCode)
        {
        }
    }
}

namespace Magento\Framework\Math {
    class Random
    {
        public const CHARS_LOWERS = 'abcdefghijklmnopqrstuvwxyz';
        public const CHARS_DIGITS = '0123456789';

        public function getRandomString($length, $chars = null)
        {
            return str_repeat('a', $length);
        }
    }
}

namespace Magento\Sales\Model\ResourceModel\Order\Invoice {
    class CollectionFactory
    {
        public function create()
        {
        }
    }
}

namespace Magento\Framework\App\Config {
    /** System configuration backend model (the real one extends AbstractModel too). */
    class Value extends \Magento\Framework\Model\AbstractModel
    {
        public function beforeSave()
        {
            return $this;
        }
    }

    interface ScopeConfigInterface
    {
        public const SCOPE_TYPE_DEFAULT = 'default';

        public function getValue($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null);

        public function isSetFlag($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null);
    }
}

namespace Magento\Store\Model {
    interface ScopeInterface
    {
        public const SCOPE_STORE = 'store';
        public const SCOPE_STORES = 'stores';
        public const SCOPE_WEBSITE = 'website';
        public const SCOPE_WEBSITES = 'websites';
        public const SCOPE_GROUP = 'group';
    }

    interface StoreManagerInterface
    {
        public function getStore($storeId = null);

        /** @return \Magento\Store\Api\Data\StoreInterface[] */
        public function getStores($withDefault = false, $codeKey = false);
    }

    /** getBaseUrl() belongs to the concrete Store model, not to StoreInterface. */
    abstract class Store implements \Magento\Store\Api\Data\StoreInterface
    {
        public function getBaseUrl($type = 'link', $secure = null)
        {
            return '';
        }
    }
}

namespace Magento\Store\Api\Data {
    interface StoreInterface
    {
        public function getId();
    }
}

namespace Magento\Framework\Notification {
    interface MessageInterface
    {
        public const SEVERITY_CRITICAL = 1;
        public const SEVERITY_MAJOR = 2;
        public const SEVERITY_MINOR = 3;
        public const SEVERITY_NOTICE = 4;

        public function getIdentity();

        public function isDisplayed();

        public function getText();

        public function getSeverity();
    }
}

namespace Magento\Framework\Model {
    abstract class AbstractModel
    {
        protected array $_data = [];

        public function _init($resourceModel)
        {
        }

        public function getData($key = null)
        {
            if ($key === null) {
                return $this->_data;
            }
            return $this->_data[$key] ?? null;
        }

        public function setData($key, $value = null)
        {
            if (is_array($key)) {
                $this->_data = $key;
            } else {
                $this->_data[$key] = $value;
            }
            return $this;
        }

        public function getId()
        {
            return $this->getData('id') ?? $this->getData('subscription_id') ?? $this->getData('consumer_id');
        }

        public function isObjectNew()
        {
            return !$this->getId();
        }

        public function dataHasChangedFor($key)
        {
            return true;
        }
    }
}

namespace Magento\Framework\Model\ResourceModel\Db {
    abstract class AbstractDb
    {
        public function __construct(...$args)
        {
        }

        public function save(\Magento\Framework\Model\AbstractModel $object)
        {
            return $this;
        }

        public function load(\Magento\Framework\Model\AbstractModel $object, $value, $field = null)
        {
            return $this;
        }

        public function delete(\Magento\Framework\Model\AbstractModel $object)
        {
            return $this;
        }
    }
}

namespace Magento\Framework\MessageQueue {
    interface PublisherInterface
    {
        public function publish($topicName, $data);
    }
}

namespace Magento\Framework\Event {
    interface ManagerInterface
    {
        public function dispatch($name, array $data = []);
    }
}

namespace Magento\Framework\Serialize\Serializer {
    class Json
    {
        public function serialize($data): string
        {
            $result = json_encode($data);
            if ($result === false) {
                throw new \InvalidArgumentException('Unable to serialize value.');
            }

            return $result;
        }

        public function unserialize($string)
        {
            $result = json_decode((string)$string, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Unable to unserialize value.');
            }

            return $result;
        }
    }
}

namespace Magento\Framework\HTTP\Client {
    class Curl
    {
        public function addHeader($name, $value)
        {
        }

        public function setOption($name, $value)
        {
        }

        public function get($uri)
        {
        }

        public function post($uri, $params)
        {
        }

        public function getStatus(): int
        {
            return 200;
        }

        public function getBody(): string
        {
            return '';
        }
    }

    class CurlFactory
    {
        public function create(array $data = [])
        {
            return new Curl();
        }
    }
}

namespace Magento\Framework\Url {
    class Validator
    {
        public function isValid($value): bool
        {
            return (bool)filter_var((string)$value, FILTER_VALIDATE_URL);
        }
    }
}

namespace Magento\Framework\Encryption {
    interface EncryptorInterface
    {
        public function encrypt($data);

        public function decrypt($data);
    }
}

namespace Magento\Authorization\Model {
    interface UserContextInterface
    {
        public const USER_TYPE_ADMIN = 0;
        public const USER_TYPE_INTEGRATION = 1;
        public const USER_TYPE_CUSTOMER = 2;
        public const USER_TYPE_GUEST = 3;

        public function getUserId();

        public function getUserType();
    }
}

namespace Magento\Framework\Pricing {
    interface PriceCurrencyInterface
    {
        public const DEFAULT_PRECISION = 2;
        public function format($amount, $includeContainer = true, $precision = self::DEFAULT_PRECISION, $scope = null, $currency = null);
    }
}

namespace Magento\Framework\Stdlib\DateTime {
    interface TimezoneInterface
    {
        public function formatDateTime($date = null, $format = \IntlDateFormatter::SHORT, $showTime = \IntlDateFormatter::SHORT, $locale = null, $timezone = null);
    }

    class DateTime
    {
        public function gmtTimestamp($input = null): int
        {
            return $input === null ? time() : (int)strtotime((string)$input);
        }
    }
}

namespace Magento\Sales\Api\Data {
    /**
     * Subset of the OrderInterface methods used by the module. getData()
     * belongs to the concrete Order model (see Magento\Sales\Model\Order).
     */
    interface OrderInterface
    {
        public function getEntityId();

        public function getStoreId();

        public function getCustomerEmail();

        public function getItems();

        public function getBillingAddress();

        public function getIncrementId();

        public function getGrandTotal();

        public function getOrderCurrencyCode();

        public function getCustomerFirstname();

        public function getCustomerLastname();

        public function getCreatedAt();
    }

    interface OrderAddressInterface
    {
        public function getFirstname();

        public function getLastname();
    }

    interface InvoiceInterface
    {
        public function getEntityId();

        public function getIncrementId();

        public function getCreatedAt();
    }
}

namespace Magento\Sales\Model {
    abstract class Order implements \Magento\Sales\Api\Data\OrderInterface
    {
        public function getData($key = '', $index = null)
        {
            return null;
        }

        public function getCustomerName()
        {
            return '';
        }
    }
}

namespace Magento\Sales\Model\Order {
    abstract class Address implements \Magento\Sales\Api\Data\OrderAddressInterface
    {
        public function getName()
        {
            return '';
        }
    }
}

namespace Magento\Sales\Api {
    interface OrderRepositoryInterface
    {
        public function get($id);
    }
}

namespace MageOS\DigitalSignature\Model {
    /**
     * Stub della factory generata da Magento (setup:di:compile). Nei test va
     * sostituita con un mock configurato per restituire un FakeDocument.
     */
    class DocumentFactory
    {
        public function create(array $data = [])
        {
            throw new \LogicException('Stub DocumentFactory: configurare un mock nel test.');
        }
    }

    class WebhookSubscriptionFactory
    {
        public function create(array $data = [])
        {
            throw new \LogicException('Stub WebhookSubscriptionFactory: configurare un mock nel test.');
        }
    }

    class ApiConsumerFactory
    {
        public function create(array $data = [])
        {
            throw new \LogicException('Stub ApiConsumerFactory: configurare un mock nel test.');
        }
    }

    class AuditTrailFactory
    {
        public function create(array $data = [])
        {
            throw new \LogicException('Stub AuditTrailFactory: configurare un mock nel test.');
        }
    }

    class DossierExportFactory
    {
        public function create(array $data = [])
        {
            throw new \LogicException('Stub DossierExportFactory: configurare un mock nel test.');
        }
    }
}

namespace Magento\Framework\Event {
    class Observer
    {
        /** @var array<string, mixed> */
        private array $data = [];

        public function setData(string $key, $value): self
        {
            $this->data[$key] = $value;

            return $this;
        }

        public function getData(string $key)
        {
            return $this->data[$key] ?? null;
        }

        public function getEvent(): self
        {
            return $this;
        }
    }

    interface ObserverInterface
    {
        public function execute(Observer $observer);
    }
}

namespace Magento\AdminNotification\Model {
    class Inbox
    {
        public function addNotice($title, $description, $url = '', $isInternal = true): self
        {
            return $this;
        }

        public function addMajor($title, $description, $url = '', $isInternal = true): self
        {
            return $this;
        }
    }

    class InboxFactory
    {
        public function create(): Inbox
        {
            return new Inbox();
        }
    }
}

namespace Magento\Framework\DB {
    /**
     * Stub minimale di Zend_Db_Select/Magento\Framework\DB\Select: registra le
     * chiamate a joinLeft()/where() cosi' i test unitari possono ispezionarle
     * senza eseguire SQL reale.
     */
    class Select
    {
        /** @var array<int, array{0: array|string, 1: string, 2: array|string}> */
        public array $joinLeftCalls = [];

        /** @var array<int, array{0: string, 1: mixed}> */
        public array $whereCalls = [];

        public function joinLeft($name, $cond, $cols = '*'): self
        {
            $this->joinLeftCalls[] = [$name, $cond, $cols];
            return $this;
        }

        public function where($cond, $value = null, $type = null): self
        {
            $this->whereCalls[] = [$cond, $value];
            return $this;
        }

        public function __toString(): string
        {
            return implode(' AND ', array_column($this->whereCalls, 0));
        }
    }
}

namespace Magento\Framework\DB\Adapter {
    interface AdapterInterface
    {
        public function quoteInto(string $text, $value, ?string $type = null, ?int $count = null): string;

        public function update($table, array $bind, $where = '');
    }
}

namespace {
    /**
     * Stub minimale dell'adapter di connessione: replica solo i metodi usati
     * dal codice del modulo nei test unitari (quoteInto(), update()).
     */
    class MageOSTestDbAdapter implements \Magento\Framework\DB\Adapter\AdapterInterface
    {
        public function quoteInto(string $text, $value, ?string $type = null, ?int $count = null): string
        {
            $quoted = is_string($value) ? "'" . addslashes($value) . "'" : (string)$value;
            return str_replace('?', $quoted, $text);
        }

        public function update($table, array $bind, $where = '')
        {
            return 0;
        }
    }
}

namespace Magento\Framework\Model\ResourceModel\Db\Collection {
    abstract class AbstractCollection implements \IteratorAggregate
    {
        /** @var array<int, object> */
        protected array $_items = [];

        private ?\Magento\Framework\DB\Select $_select = null;

        private ?\Magento\Framework\DB\Adapter\AdapterInterface $_connection = null;

        public function addFieldToFilter($field, $condition = null)
        {
            return $this;
        }

        public function getSize(): int
        {
            return count($this->_items);
        }

        public function setPageSize($size)
        {
            return $this;
        }

        public function setOrder($field, $direction = 'DESC')
        {
            return $this;
        }

        public function getSelect(): \Magento\Framework\DB\Select
        {
            if ($this->_select === null) {
                $this->_select = new \Magento\Framework\DB\Select();
            }
            return $this->_select;
        }

        public function getConnection(): \Magento\Framework\DB\Adapter\AdapterInterface
        {
            if ($this->_connection === null) {
                $this->_connection = new \MageOSTestDbAdapter();
            }
            return $this->_connection;
        }

        public function getTable($table)
        {
            return $table;
        }

        /**
         * Helper di test: popola la collection con item gia' pronti, senza
         * passare da una query reale (usato dai fake ...WithIds() nei test).
         */
        public function addItem($item): self
        {
            $this->_items[] = $item;
            return $this;
        }

        public function getIterator(): \ArrayIterator
        {
            return new \ArrayIterator($this->_items);
        }
    }
}

namespace Magento\Framework {
    interface UrlInterface
    {
        public function getUrl($routePath = null, $routeParams = null);
    }
}

namespace Magento\Framework\View\Element {
    class UiComponentFactory
    {
    }
}

namespace Magento\Ui\Component\Listing\Columns {
    use Magento\Framework\View\Element\UiComponent\ContextInterface;
    use Magento\Framework\View\Element\UiComponentFactory;

    class Column
    {
        protected array $_data;

        public function __construct(
            ContextInterface $context,
            UiComponentFactory $uiComponentFactory,
            array $components = [],
            array $data = []
        ) {
            $this->_data = $data;
        }

        public function getData($key = '')
        {
            return $key === '' ? $this->_data : ($this->_data[$key] ?? null);
        }
    }
}

namespace MageOS\DigitalSignature\Model\ResourceModel\Document {
    class CollectionFactory
    {
        public function create(): \MageOS\DigitalSignature\Model\ResourceModel\Document\Collection
        {
            return new \MageOS\DigitalSignature\Model\ResourceModel\Document\Collection();
        }
    }
}

namespace MageOS\DigitalSignature\Model\ResourceModel\AuditTrail {
    class CollectionFactory
    {
        public function create(): \MageOS\DigitalSignature\Model\ResourceModel\AuditTrail\Collection
        {
            return new \MageOS\DigitalSignature\Model\ResourceModel\AuditTrail\Collection();
        }
    }
}

namespace MageOS\DigitalSignature\Model\ResourceModel\DossierExport {
    class CollectionFactory
    {
        public function create(): \MageOS\DigitalSignature\Model\ResourceModel\DossierExport\Collection
        {
            return new \MageOS\DigitalSignature\Model\ResourceModel\DossierExport\Collection();
        }
    }
}

namespace {
    if (!function_exists('__')) {
        /**
         * Replica della funzione di traduzione Magento: ritorna una Phrase.
         */
        function __(...$argc): \Magento\Framework\Phrase
        {
            $text = (string)array_shift($argc);
            if (isset($argc[0]) && is_array($argc[0])) {
                $argc = $argc[0];
            }

            return new \Magento\Framework\Phrase($text, $argc);
        }
    }
}
