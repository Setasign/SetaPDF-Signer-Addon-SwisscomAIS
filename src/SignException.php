<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

class SignException extends Exception
{
    /**
     * @var array
     */
    protected $request;

    /**
     * @var array
     */
    protected $response;

    public function __construct(array $request, array $response)
    {
        $this->request = $request;
        $this->response = $response;
        parent::__construct('Error on creating signature. ' . ($response['SignResponse']['Result']['ResultMessage']['$'] ?? $this->getResultMajor()));
    }

    public function getRequestData(): array
    {
        return $this->request;
    }

    public function getResponseData(): array
    {
        return $this->response;
    }

    /**
     * Get the ResultMajor status code of the response.
     *
     * @return string|null
     */
    public function getResultMajor(): ?string
    {
        if (isset($this->response['SignResponse']['Result']['ResultMajor'])) {
            return $this->response['SignResponse']['Result']['ResultMajor'];
        }

        return null;
    }

    /**
     * Get the ResultMinor status code of the response.
     *
     * @return string|null
     */
    public function getResultMinor(): ?string
    {
        if (isset($this->response['SignResponse']['Result']['ResultMinor'])) {
            return $this->response['SignResponse']['Result']['ResultMinor'];
        }

        return null;
    }
}
