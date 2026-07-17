<?php
declare(strict_types=1);

namespace MageOS\DigitalSignature\TestSupport;

use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Doppio di test di CurlFactory: ogni chiamata a create() restituisce la
 * prossima FakeCurl accodata. Serve per i flussi che eseguono più richieste
 * HTTP in sequenza con risposte diverse (es. autenticazione JWT DocuSign:
 * token OAuth + userinfo + chiamata API).
 */
class SequencedCurlFactory extends CurlFactory
{
    /** @var FakeCurl[] */
    private array $queue;

    /** @var FakeCurl[] */
    public array $created = [];

    public function __construct(FakeCurl ...$queue)
    {
        $this->queue = $queue;
    }

    public function create(array $data = []): FakeCurl
    {
        $curl = array_shift($this->queue) ?? new FakeCurl();
        $this->created[] = $curl;

        return $curl;
    }
}
