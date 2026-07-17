# Test e CI — MageOS_DigitalSignature

## Unit test (standalone, senza Magento)

I test vivono in `src/Test/Unit` (convenzione Magento) ma girano **senza
un'installazione Magento**: il bootstrap (`tests/bootstrap.php`) carica stub
minimi del framework (`tests/stubs/MagentoStubs.php`) solo quando le classi
reali non sono nell'autoload. Dentro un'installazione Magento gli stub vengono
ignorati automaticamente.

```bash
composer install          # solo phpunit + psr/log
vendor/bin/phpunit        # oppure: composer test
```

Supporto test in `tests/Support/`: `FakeDocument` (DocumentInterface in
memoria) e `FakeCurl` (doppio del client HTTP Magento).

### Cosa è coperto

- `TagReplacer`: parsing tag nei PDF (stream piani e FlateDecode), sostituzione
  email con incremental update + sbiancamento, escaping delimitatori PDF, casi
  d'errore (non-PDF, senza tag, senza xref classica).
- `TemplateValidator`: cap dimensione, magic bytes, xref, presenza tag.
- `TriggerHandler`: enforcement server-side della scelta cliente, catena di
  risoluzione trigger (override prodotto → template → default), righe figlie
  saltate, dedup su indice univoco, errori non propagati.
- `DocumentManager`: rigenerazione (storicizzazione + cancel best-effort lato
  provider + nuovo documento in coda).
- `WsSign`: mappatura stati configurabile (case-insensitive, fallback 404).
- `WsSign\Client`: classificazione errori (5xx/429 retryable, 4xx permanenti,
  trasporto retryable), 404 tollerati dove previsto, validazione PDF scaricato.
- `Status`, `ProviderException`.

Non coperto a unit (richiede integrazione/E2E in ambiente):
`DocumentProcessor`, `SignatureCartContext` (sessione checkout), controller,
consumer. Restano verificati con il provider `dummy` nell'ambiente Docker
`../firmadigitale-magento`.

## CI (GitHub Actions)

`.github/workflows/ci.yml`, su ogni push/PR:

1. **lint** — `php -l` su PHP 8.1/8.2/8.3/8.4 (php + phtml)
2. **xml** — `xmllint --noout` su tutti gli XML
3. **unit** — la suite PHPUnit su PHP 8.1 e 8.3
4. **coding-standard** — `phpcs --standard=Magento2 -n` (solo errori)

## Esecuzione nell'ambiente Docker (opzionale)

Con l'ambiente `../firmadigitale-magento` avviato, la stessa suite gira anche
nel container (framework reale al posto degli stub):

```bash
cd ../firmadigitale-magento
bin/clinotty vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist \
  app/code/MageOS/DigitalSignature/Test/Unit
```
