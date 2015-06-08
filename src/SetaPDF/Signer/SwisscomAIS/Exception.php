<?php
/**
 * This file is part of the demo pacakge of the SetaPDF-Signer Component
 *
 * @copyright  Copyright (c) 2015 Setasign - Jan Slabon (http://www.setasign.com)
 * @category   SetaPDF
 * @package    SetaPDF_Signer
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 */

/**
 * Exception class for Swisscom AIS webservice.
 *
 * @copyright  Copyright (c) 2015 Setasign - Jan Slabon (http://www.setasign.com)
 * @category   SetaPDF
 * @package    SetaPDF_Signer
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 */
class SetaPDF_Signer_SwisscomAIS_Exception extends SetaPDF_Signer_Exception
{
    /**
     * @var stdClass|array
     */
    protected $_request;

    /**
     * @var stdClass
     */
    protected $_result;

    /**
     * Set the request data.
     *
     * @param $request
     */
    public function setRequest($request)
    {
        $this->_request = $request;
    }

    /**
     * Get the request data.
     *
     * @return array|stdClass
     */
    public function getRequest()
    {
        return $this->_request;
    }

    /**
     * Set the result of the webservice call.
     *
     * @param stdClass $result
     */
    public function setResult($result)
    {
        $this->_result = $result;
    }

    /**
     * Get the result of the webservice call.
     * @return stdClass
     */
    public function getResult()
    {
        return $this->_result;
    }

    /**
     * Get the Mobile ID User Assistance URL (if available).
     *
     * @return null|string
     */
    public function getMobileIdUserAssistanceUrl()
    {
        $lastResult = $this->getResult();
        if (isset($lastResult->SignResponse->OptionalOutputs->MobileIDFault->Detail->UserAssistance->PortalUrl)) {
            return $lastResult->SignResponse->OptionalOutputs->MobileIDFault->Detail->UserAssistance->PortalUrl;
        }

        return null;
    }
}