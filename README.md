# Swisscom AIS signature add-on for the SetaPDF-Signer component

This add-on offers individual signature modules and batch signature functionalities for the 
[SetaPDF-Signer Component](https://www.setasign.com/signer) that allows you to use the [Swisscom All-in Signing Service](https://trustservices.swisscom.com/signing-service/)
to digital **sign PDF documents in pure PHP**.

The implementation is based on the [All-in Signing Service Reference Guide Version 2.10](https://documents.swisscom.com/product/1000255-Digital_Signing_Service/Documents/Reference_Guide/Reference_Guide-All-in-Signing-Service-en.pdf).

The form for requesting a test account at Swisscom includes the Claimed IDs for testing and is available [here](https://documents.swisscom.com/product/filestore/lib/2c3229a1-3e50-4f3d-a957-ec1b55ade6dc/orderpermanenttestaccount-de.pdf).

## Requirements
To use this add-on you need credentials for the Swisscom AIS webservice.

This add-on is developed and tested on PHP >= 7.2. Requirements of the [SetaPDF-Signer](https://www.setasign.com/signer)
component can be found [here](https://manuals.setasign.com/setapdf-signer-manual/getting-started/#index-1).

We're using [PSR-17 (HTTP Factories)](https://www.php-fig.org/psr/psr-17/) and [PSR-18 (HTTP Client)](https://www.php-fig.org/psr/psr-18/)
for the requests. So you'll need an implementation of these. We recommend using Guzzle. Note: Your implementation must
support client-side certificates.

```
    "require" : {
        "guzzlehttp/guzzle": "^7.0",
        "http-interop/http-factory-guzzle": "^1.0"
    }
```

## Installation
Add following to your composer.json:

```json
{
    "require": {
        "setasign/setapdf-signer-addon-swisscomais": "^2.0"
    },

    "repositories": [
        {
            "type": "composer",
            "url": "https://www.setasign.com/downloads/"
        }
    ]
}
```

and execute `composer update`. You need to define the `repository` to evaluate the dependency to the
[SetaPDF-Signer](https://www.setasign.com/signer) component
(see [here](https://getcomposer.org/doc/faqs/why-can%27t-composer-load-repositories-recursively.md) for more details).

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
