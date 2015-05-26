# Swisscom AIS signature module and batch functionallity for the SetaPDF-Signer component

This package offers an individual module for the [SetaPDF-Signer Component](https://www.setasign.com/signer) that allows
you to use the [Swisscom All-in Signing Service](https://www.swisscom.ch/en/business/enterprise/offer/security/identity-access-security/signing-service.html) 
for the signature process of PDF documents. A big advantage of this module is, that it only transfers the hash, that 
should be signed, to Swisscom AIS webservice and not the complete PDF document. The returned signature will be placed in
the PDF document by the SetaPDF-Signer Component.

Furthermore this add-on comes with a batch class allowing to digital sign several documents with a single webservice
call.

## Installation
Add following to your composer.json:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "http://www.setasign.com/downloads/"
        }
    ],
    "require": {
        "setasign/setapdf-signer-addon-swisscomais": "1.*"
    }
}
```

By default this packages depends on a licensed version of the SetaPDF-Signer component. If you want to use it with an [evaluation version](https://www.setasign.com/products/setapdf-signer/evaluate/) please use following in your composer.json:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "http://www.setasign.com/downloads/"
        }
    ],
    "require": {
        "setasign/setapdf-signer-addon-swisscomais": "dev-evaluation"
    }
}
```
