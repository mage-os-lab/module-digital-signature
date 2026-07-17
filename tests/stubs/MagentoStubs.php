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

namespace Magento\Framework\App {
    interface CacheInterface
    {
        public function load($identifier);

        public function save($data, $identifier, array $tags = [], $lifeTime = null);
    }
}

namespace Magento\Framework\App\Config {
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
    }
}

namespace Magento\Store\Api\Data {
    interface StoreInterface
    {
        public function getBaseUrl($type = null, $secure = null);
    }
}

namespace Magento\Framework\Model\ResourceModel\Db {
    abstract class AbstractDb
    {
        public function __construct(...$args)
        {
        }
    }
}

namespace Magento\Framework\MessageQueue {
    interface PublisherInterface
    {
        public function publish($topicName, $data);
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
}

namespace Magento\Sales\Api\Data {
    /**
     * Sottoinsieme dei metodi di OrderInterface usati dal modulo; getData()
     * appartiene al modello concreto Order ma viene dichiarato qui per
     * permettere il mock nel test del TriggerHandler.
     */
    interface OrderInterface
    {
        public function getEntityId();

        public function getStoreId();

        public function getCustomerEmail();

        public function getItems();

        public function getBillingAddress();

        public function getData($key = '', $index = null);

        public function getIncrementId();

        public function getGrandTotal();

        public function getOrderCurrencyCode();

        public function getCustomerFirstname();

        public function getCustomerLastname();

        public function getCustomerName();

        public function getCreatedAt();
    }

    interface OrderAddressInterface
    {
        public function getName();
    }

    interface InvoiceInterface
    {
        public function getEntityId();

        public function getIncrementId();

        public function getCreatedAt();
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
