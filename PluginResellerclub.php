<?php
require_once 'modules/admin/models/RegistrarPlugin.php';
require_once 'library/CE/NE_Network.php';
require_once 'modules/domains/models/ICanImportDomains.php';

/**
* @package Plugins
*/
class PluginResellerclub extends RegistrarPlugin implements ICanImportDomains
{
    function getVariables()
    {
        $variables = array(
            lang('Plugin Name') => array (
                                'type'          =>'hidden',
                                'description'   =>lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                                'value'         =>lang('ResellerClub')
                               ),
            lang('Use testing server') => array(
                                'type'          =>'yesno',
                                'description'   =>lang('Select Yes if you wish to use the ResellerClub testing environment, so that transactions are not actually made.<br><br><b>Note: </b>You will first need to register for a demo account at<br>http://cp.onlyfordemo.net/servlet/ResellerSignupServlet?&validatenow=false.'),
                                'value'         =>0
                               ),
            lang('Reseller ID') => array(
                                'type'          =>'text',
                                'description'   =>lang('Enter your ResellerClub Reseller ID.  This can be found in your ResellerClub account by going to your profile link, in the top right corner.'),
                                'value'         =>''
                               ),
            lang('Password') => array(
                                'type'          =>'password',
                                'description'   =>lang('Enter the password for your ResellerClub reseller account.'),
                                'value'         =>''
                               ),
            lang('API Key') => array(
                                'type'          =>'text',
                                'description'   =>lang('Enter your API Key for your ResellerClub reseller account.  You should use this instead of your password, however you still may use your password instead.'),
                                'value'         =>''
                               ),
            lang('Supported Features')  => array(
                                'type'          => 'label',
                                'description'   => '* '.lang('TLD Lookup').'<br>* '.lang('Domain Registration').' <br>* '.lang('Existing Domain Importing').' <br>* '.lang('Get / Set Nameserver Records').' <br>* '.lang('Get / Set Contact Information').' <br>* '.lang('Get / Set Registrar Lock').' <br>* '.lang('Initiate Domain Transfer').' <br>* ' . lang('Automatically Renew Domain'),
                                'value'         => ''
                                ),
            lang('Actions') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
                                'value'         => 'Register'
                                ),
            lang('Registered Actions') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                                'value'         => 'Renew (Renew Domain),DomainTransferWithPopup (Initiate Transfer),Cancel',
                                ),
             lang('Registered Actions For Customer') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                                'value'         => '',
            )
        );

        return $variables;
    }

    function checkDomain($params)
    {
        $arguments = array(
            'domain-name'         => $params['sld'],
            'tlds'                => $params['tld'],
            'suggest-alternative' => false
        );

        $domain = strtolower($params['sld'] . '.' . $params['tld']);

        $result = $this->_makeGetRequest('/domains/available', $arguments);
        if ($result == false) {
            $status = 5;
        }
        else if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub check domain failed with error: ' . $result->message);
            $status = 2;
        }
        else if ($result->$domain->status == 'regthroughus' || $result->$domain->status == 'regthroughothers') {
            CE_Lib::log(4, 'ResellerClub check domain result for domain ' . $domain . ': Registered');
            $status = 1;
        }
        else if ($result->$domain->status == 'available') {
            CE_Lib::log(4, 'ResellerClub check domain result for domain ' . $domain . ': Available');
            $status = 0;
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub check domain failed.');
            $status = 5;
        }
        $domains = array();
        $domains[] = array('tld' => $params['tld'], 'domain' => $params['sld'], 'status' => $status);
        return array("result"=>$domains);
    }


    function doTogglePrivacy($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $status = $this->togglePrivacy($this->buildRegisterParams($userPackage,$params));
        return "Turned privacy {$status} for " . $userPackage->getCustomField('Domain Name') . '.';
    }

    function togglePrivacy($params)
    {
        $params['sld'] = strtolower($params['sld']);
        $params['tld'] = strtolower($params['tld']);
        $domain = $params['sld'].".".$params['tld'];

        $domainId = $this->_lookupDomainId($domain);
        if (is_a($domainId, 'CE_Error')) {
            throw new Exception($domainId, EXCEPTION_CODE_CONNECTION_ISSUE);
        }

        $arguments = array(
            'order-id'      => $domainId,
            'options'       => array('All'),
        );

        $result = $this->_makeGetRequest('/domains/details', $arguments);

        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.', EXCEPTION_CODE_CONNECTION_ISSUE);
        }
        if ( isset($result->isprivacyprotected) ) {
            $privacyStatus = $result->isprivacyprotected;

            if ( $privacyStatus == 'false' ) {
                $toggled = 'true';
            } else {
                $toggled = 'false';
            }

            $arguments = array(
                'order-id'              => $domainId,
                'protect-privacy'       => $toggled,
                'reason'                => 'Requested by admin in ClientExec'
            );

            $result = $this->_makePostRequest('/domains/modify-privacy-protection', $arguments);
            if ($result === false) {
                throw new Exception('A connection issued occurred while connecting to ResellerClub.', EXCEPTION_CODE_CONNECTION_ISSUE);
            }
            if (isset($result->status) && strtolower($result->status) == 'error') {
                CE_Lib::log(4, 'Error toggling privacy protection: ' . $result->message);
                throw new Exception('Error toggling privacy protection: ' . $result->message);
            } else if ( isset($result->actionstatus) && strtolower($result->actionstatus) == 'success' ) {
                if ( $toggled == 'true' ) {
                    return 'on';
                } else {
                    return 'off';
                }
            }  else {
                CE_Lib::log(4, 'Error toggling privacy protection: Unknown Reason');
                throw new Exception('Error toggling privacy protection.');
            }
        }  else if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub domain details fetch failed with error: ' . $result->message);
            throw new Exception('Error fetching ResellerClub domain details.: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub domain details fetch failed with error');
            throw new Exception('Error fetching ResellerClub domain details.');
        }
    }



    /**
     * Register domain name
     *
     * @param array $params
     */
    function doRegister($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->registerDomain($this->buildRegisterParams($userPackage,$params));
        $userPackage->setCustomField("Registrar Order Id",$userPackage->getCustomField("Registrar").'-'.$orderid[1][0]);
        return $userPackage->getCustomField('Domain Name') . ' has been registered.';
    }

    /**
     * Renew domain name
     *
     * @param array $params
     */
    function doRenew($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->renewDomain($this->buildRenewParams($userPackage,$params));
        return $userPackage->getCustomField('Domain Name') . ' has been renewed.';
    }


    function registerDomain($params)
    {
        $contactType = $this->getContactType($params);

        $newCustomer = false;
        $countrycode = $this->_getCountryCode($params['RegistrantCountry']);
        $telno = $this->_validatePhone($params['RegistrantPhone'],$countrycode);
        if ($params['RegistrantOrganizationName'] == "") $params['RegistrantOrganizationName'] = "N/A";
        $customerId = $this->_lookupCustomerId($params['RegistrantEmailAddress']);
        if (is_a($customerId, 'CE_Error')) {
            CE_Lib::log(4, 'Error creating ResellerClub customer: ' . $customerId->getMessage());
            throw new CE_Exception('Error creating ResellerClub customer: ' . $customerId->getMessage());
        }
        if ($customerId === false) {
            // Customer doesn't already exist so create one.
            $newCustomer = true;

            $arguments = array(
                'username'              => $params['RegistrantEmailAddress'],
                'passwd'                => $params['DomainPassword'],
                'name'                  => $params['RegistrantFirstName']." ".$params['RegistrantLastName'],
                'company'               => $params['RegistrantOrganizationName'],
                'address-line-1'        => $params['RegistrantAddress1'],
                'city'                  => $params['RegistrantCity'],
                'state'                 => $params['RegistrantStateProvince'],
                'country'               => $params['RegistrantCountry'],
                'zipcode'               => strtoupper($params['RegistrantPostalCode']),
                'phone-cc'              => $countrycode,
                'phone'                 => $telno,
                'lang-pref'             => 'en'
            );
            $result = $this->_makePostRequest('/customers/signup', $arguments);

            if (is_numeric($result)) {
                $customerId = $result;
            } else if (isset($result->status) && $result->status == 'ERROR') {
                CE_Lib::log(4, 'Error creating ResellerClub customer: ' . $result->message);
                throw new CE_Exception('Error creating ResellerClub customer: ' . $result->message);
            } else {
                CE_Lib::log(4, 'Error creating ResellerClub customer: Unknown Reason');
                throw new Exception('Error creating ResellerClub customer.');
            }
        }

        $contactId = 0;
        $arguments = array(
            'name'                  => $params['RegistrantFirstName']." ".$params['RegistrantLastName'],
            'company'               => $params['RegistrantOrganizationName'],
            'email'                 => $params['RegistrantEmailAddress'],
            'address-line-1'        => $params['RegistrantAddress1'],
            'city'                  => $params['RegistrantCity'],
            'state'                 => $params['RegistrantStateProvince'],
            'country'               => $params['RegistrantCountry'],
            'zipcode'               => $params['RegistrantPostalCode'],
            'phone-cc'              => $countrycode,
            'phone'                 => $telno,
            'customer-id'           => $customerId,
            'type'                  => $contactType,
        );
        // Handle any extra attributes needed
        if (isset($params['ExtendedAttributes']) && is_array($params['ExtendedAttributes'])) {
            if ( $params['tld'] == 'ca' )  {
                $arguments['attr-name1'] = 'CPR';
                $arguments['attr-value1'] = $params['ExtendedAttributes']['cira_legal_type'];

                $arguments['attr-name2'] = 'AgreementVersion';
                $arguments['attr-value2'] = $params['ExtendedAttributes']['cira_agreement_version'];

                $arguments['attr-name3'] = 'AgreementValue';
                $arguments['attr-value3'] = $params['ExtendedAttributes']['cira_agreement_value'];

            } else if ( $params['tld'] == 'us' ) {
                $arguments['attr-name1'] = 'purpose';
                $arguments['attr-value1'] = $params['ExtendedAttributes']['us_purpose'];

                $arguments['attr-name2'] = 'category';
                $arguments['attr-value2'] = $params['ExtendedAttributes']['us_nexus'];

            } else {
                $i = 0;
                foreach ($params['ExtendedAttributes'] as $name => $value) {
                    // only pass extended attributes if they have a value.
                    if ( $value != '' ) {
                        $arguments['attr-name' . $i] = $name;
                        $arguments['attr-value' . $i] = $value;
                        $i++;
                    }
                }
            }
        }

        $result = $this->_makePostRequest('/contacts/add', $arguments);

        if (is_numeric($result)) {
            CE_Lib::log(4, 'ResellerClub contact id created (or retrieved) with a value of ' . $result);
            $contactId = $result;
        } else if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub customer contact creation failed with error: ' . $result->message);
            throw new CE_Exception('Error creating ResellerClub customer contact: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub customer contact creation failed: Unknown Reason.');
            throw new Exception('Error creating ResellerClub customer contact.');
        }

        // Finally, it's time to actualy register the domain.
        $domain = $params['sld'].".".$params['tld'];

        if ($params['Use testing server'] || !isset($params['NS1']) || !isset($params['NS2'])) {
            // Required nameservers for test server
            $nameservers = array(
                'dns1.parking-page.net',
                'dns2.parking-page.net'
            );
        } else {
            for ($i = 1; $i <= 12; $i++) {
                if (isset($params["NS$i"])) {
                    $nameservers[] = $params["NS$i"]['hostname'];
                } else {
                    break;
                }
            }
        }

        $purchasePrivacy = false;
        if ( isset($params['package_addons']['IDPROTECT']) && $params['package_addons']['IDPROTECT'] == 1 ) {
            $purchasePrivacy = true;
        }

        $arguments = array(
            'domain-name'           => $domain,
            'years'                 => $params['NumYears'],
            'ns'                    => $nameservers,
            'customer-id'           => $customerId,
            'reg-contact-id'        => $contactId,
            'admin-contact-id'      => $this->getAdminContactId($params['tld'], $contactId),
            'tech-contact-id'       => $this->getTechContactId($params['tld'], $contactId),
            'billing-contact-id'    => $this->getBillingContactId($params['tld'], $contactId),
            'invoice-option'        => 'NoInvoice',
            'purchase-privacy'      => $purchasePrivacy,
            'protect-privacy'       => $purchasePrivacy
        );

        $result = $this->_makePostRequest('/domains/register', $arguments);

        if ($result === false) {
            // Already logged
            throw new Exception('Error registering ResellerClub domain: A communication problem occurred.');
        }
        if (isset($result->status) && $result->status == 'Success') {
            CE_Lib::log(4, 'ResellerClub domain registration of ' . $domain . ' successful.  EntityId: ' . $result->entityid);
            return array(1, array($result->entityid));
        }
        if (isset($result->status) && strtolower($result->status) == 'error') {
            if ( isset($result->message) ) {
                $errorMessage = $result->message;
            }
            if ( isset($result->error) ) {
                $errorMessage = $result->error;
            }

            CE_Lib::log(4, 'ERROR: ResellerClub domain registration failed with error: ' . $errorMessage);
            throw new CE_Exception('Error registering ResellerClub domain: ' . $errorMessage);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub domain registration failed with error: Unknown Reason.');
            throw new Exception('Error registering ResellerClub domain.');
        }
    }

    function renewDomain($params)
    {
        $domain = $params['sld'].".".$params['tld'];

        $generalInformation = $this->getGeneralInfo($params);

        $arguments = array(
            'order-id' => $generalInformation['id'],
            'years' => $params['NumYears'],
            'exp-date' => $generalInformation['endtime'],
            'invoice-option' => 'NoInvoice'
        );

        $result = $this->_makePostRequest('/domains/renew', $arguments);

        if ($result === false) {
            // Already logged
            throw new Exception('Error registering ResellerClub domain: A communication problem occurred.');
        }
        if (isset($result->status) && $result->status == 'Success') {
            CE_Lib::log(4, 'ResellerClub domain renewal of ' . $domain . ' successful.  EntityId: ' . $result->entityid);
            return array(1, array($result->entityid));
        }
        if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub domain renewal failed with error: ' . $result->message);
            throw new CE_Exception('Error renewing ResellerClub domain: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub domain renewal failed with error: Unknown Reason.');
            throw new Exception('Error renewing ResellerClub domain.');
        }
    }

    function getGeneralInfo($params)
    {
        $params['sld'] = strtolower($params['sld']);
        $params['tld'] = strtolower($params['tld']);
        $domain = $params['sld'].".".$params['tld'];

        $domainId = $this->_lookupDomainId($domain);
        if (is_a($domainId, 'CE_Error')) {
            throw new Exception($domainId, EXCEPTION_CODE_CONNECTION_ISSUE);
        }

        $arguments = array(
            'order-id'      => $domainId,
            'options'       => array('OrderDetails', 'DomainStatus'),
        );

        $result = $this->_makeGetRequest('/domains/details', $arguments);

        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.', EXCEPTION_CODE_CONNECTION_ISSUE);
        }
        if (isset($result->orderid)) {
            $data = array();
            $data['endtime'] = $result->endtime;
            $data['expiration'] = date('m/d/Y', $result->endtime);
            $data['domain'] = $result->domainname;
            $data['id'] = $result->orderid;
            $data['registrationstatus'] = isset($result->orderstatus[0])? $result->orderstatus[0] : $this->user->lang('Registered');
            $data['purchasestatus'] = isset($result->domainstatus[0])? $result->domainstatus[0] : $this->user->lang('Unable to Obtain');
            $data['autorenew'] = $result->isOrderSuspendedUponExpiry == 'false'? false : true;

            return $data;
        }
        if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub domain details fetch failed with error: ' . $result->message);
            throw new Exception('Error fetching ResellerClub domain details.: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub domain details fetch failed with error');
            throw new Exception('Error fetching ResellerClub domain details.');
        }
    }

    /**
     * Initiate a domain transfer
     *
     * @param array $params
     */
    function doDomainTransferWithPopup($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $transferid = $this->initiateTransfer($this->buildTransferParams($userPackage,$params));
        $userPackage->setCustomField("Registrar Order Id",$userPackage->getCustomField("Registrar").'-'.$transferid);
        $userPackage->setCustomField('Transfer Status', $transferid);
        return "Transfer of has been initiated.";
    }

    function initiateTransfer($params)
    {
        $contactType = $this->getContactType($params);

        $newCustomer = false;
        $countrycode = $this->_getCountryCode($params['RegistrantCountry']);
        $telno = $this->_validatePhone($params['RegistrantPhone'],$countrycode);
        if ($params['RegistrantOrganizationName'] == "") $params['RegistrantOrganizationName'] = "N/A";
        $customerId = $this->_lookupCustomerId($params['RegistrantEmailAddress']);
        if (is_a($customerId, 'CE_Error')) {
            CE_Lib::log(4, 'Error creating ResellerClub customer: ' . $customerId->getMessage());
            throw new Exception('Error creating ResellerClub customer: ' . $customerId->getMessage());
        }
        if ($customerId === false) {
            // Customer doesn't already exist so create one.
            $newCustomer = true;

            $arguments = array(
                'username'              => $params['RegistrantEmailAddress'],
                'passwd'                => $params['DomainPassword'],
                'name'                  => $params['RegistrantFirstName']." ".$params['RegistrantLastName'],
                'company'               => $params['RegistrantOrganizationName'],
                'address-line-1'        => $params['RegistrantAddress1'],
                'city'                  => $params['RegistrantCity'],
                'state'                 => $params['RegistrantStateProvince'],
                'country'               => $params['RegistrantCountry'],
                'zipcode'               => strtoupper($params['RegistrantPostalCode']),
                'phone-cc'              => $countrycode,
                'phone'                 => $telno,
                'lang-pref'             => 'en'
            );
            $result = $this->_makePostRequest('/customers/signup', $arguments);

            if (is_numeric($result)) {
                $customerId = $result;
            } else if (isset($result->status) && $result->status == 'ERROR') {
                CE_Lib::log(4, 'Error creating ResellerClub customer: ' . $result->message);
                throw new Exception('Error creating ResellerClub customer: ' . $result->message);
            } else {
                CE_Lib::log(4, 'Error creating ResellerClub customer: Unknown Reason');
                throw new Exception('Error creating ResellerClub customer.');
            }
        }

        $contactId = 0;
        $arguments = array(
            'name'                  => $params['RegistrantFirstName']." ".$params['RegistrantLastName'],
            'company'               => $params['RegistrantOrganizationName'],
            'email'                 => $params['RegistrantEmailAddress'],
            'address-line-1'        => $params['RegistrantAddress1'],
            'city'                  => $params['RegistrantCity'],
            'state'                 => $params['RegistrantStateProvince'],
            'country'               => $params['RegistrantCountry'],
            'zipcode'               => $params['RegistrantPostalCode'],
            'phone-cc'              => $countrycode,
            'phone'                 => $telno,
            'customer-id'           => $customerId,
            'type'                  => $contactType,
        );

        if ( $params['tld'] == 'ca' )  {
            $arguments['attr-name1'] = 'CPR';
            $arguments['attr-value1'] = $params['ExtendedAttributes']['cira_legal_type'];

            $arguments['attr-name2'] = 'AgreementVersion';
            $arguments['attr-value2'] = $params['ExtendedAttributes']['cira_agreement_version'];

            $arguments['attr-name3'] = 'AgreementValue';
            $arguments['attr-value3'] = $params['ExtendedAttributes']['cira_agreement_value'];
        } else if ( $params['tld'] == 'us' ) {
            $arguments['attr-name1'] = 'purpose';
            $arguments['attr-value1'] = $params['ExtendedAttributes']['us_purpose'];

            $arguments['attr-name2'] = 'category';
            $arguments['attr-value2'] = $params['ExtendedAttributes']['us_nexus'];
        } else {
            // Handle any extra attributes needed
            if (isset($params['ExtendedAttributes']) && is_array($params['ExtendedAttributes'])) {
                $i = 1;
                foreach ($params['ExtendedAttributes'] as $name => $value) {
                    // only pass extended attributes if they have a value.
                    if ( $value != '' ) {
                        $arguments['attr-name' . $i] = $name;
                        $arguments['attr-value' . $i] = $value;
                        $i++;
                    }
                }
            }
        }

        $result = $this->_makePostRequest('/contacts/add', $arguments);

        if (is_numeric($result)) {
            CE_Lib::log(4, 'ResellerClub contact id created (or retrieved) with a value of ' . $result);
            $contactId = $result;
        } else if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub customer contact creation failed with error: ' . $result->message);
            throw new Exception('Error creating ResellerClub customer contact: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub customer contact creation failed: Unknown Reason.');
            throw new Exception('Error creating ResellerClub customer contact.');
        }

        // Finally, it's time to actualy register the domain.
        $domain = $params['sld'].".".$params['tld'];

        $arguments = array(
            'domain-name'           => $domain,
            'customer-id'           => $customerId,
            'reg-contact-id'        => $contactId,
            'admin-contact-id'      => $this->getAdminContactId($params['tld'], $contactId),
            'tech-contact-id'       => $this->getTechContactId($params['tld'], $contactId),
            'billing-contact-id'    => $this->getBillingContactId($params['tld'], $contactId),
            'invoice-option'        => 'NoInvoice',
            'protect-privacy'       => false, // needs support in the future
            'auth-code'             => $params['eppCode']
        );

        $result = $this->_makePostRequest('/domains/transfer', $arguments);

        if ($result === false) {
            // Already logged
            throw new Exception('Error transfering ResellerClub domain: A communication problem occurred.');
        }
        CE_Lib::log(2, 'ResellerClub Transfer Result: ' . print_r($result, true));

        if (isset($result->status) && strtolower($result->status) == 'adminapproved' || strtolower($result->status) == 'success') {
            CE_Lib::log(4, 'ResellerClub domain transfer of ' . $domain . ' successful.  EntityId: ' . $result->entityid);
            return $result->entityid;
        }
        else if (isset($result->status) && strtolower($result->status) == 'error') {
            CE_Lib::log(4, 'ERROR: ResellerClub domain transfer failed with error: ' . $result->error);
            throw new CE_Exception('Error transfering ResellerClub domain: ' . $result->error);
        } else if ( isset($result->status) && strtolower($result->status) == 'failed') {
            CE_Lib::log(4, 'ERROR: ResellerClub domain transfer failed with error: ' . $result->actiontypedesc);
            throw new CE_Exception('Error transfering ResellerClub domain: ' . $result->actiontypedesc);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub domain transfer failed with error: Unknown Reason.');
            throw new CE_Exception('Error transfering ResellerClub domain.');
        }
    }

    function getTransferStatus($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);

        $arguments = array(
            'order-id'              => $userPackage->getCustomField('Transfer Status'),
            'no-of-records'         => 1,
            'page-no'               => 1,
        );

        $result = $this->_makeGetRequest('/actions/search-current', $arguments);

        if ($result === false) {
            throw new Exception('Error transfering ResellerClub domain: A communication problem occurred.');
        }
        if (isset($result->status) && strtolower($result->status) == 'error') {
            // If there's an error, we need to search the archived section now.
            $arguments = array(
                'order-id'              => $userPackage->getCustomField('Transfer Status'),
                'no-of-records'         => 1,
                'page-no'               => 1,
            );

            $result = $this->_makeGetRequest('/actions/search-archived', $arguments);
            if ($result === false) {
                throw new Exception('Error transfering ResellerClub domain: A communication problem occurred.');
            }
            if (isset($result->status) && strtolower($result->status) == 'error') {
                CE_Lib::log(4, 'ERROR: ResellerClub domain transfer failed with error: ' . $result->error);
                throw new Exception('Error transfering ResellerClub domain: ' . $result->error);
            }
            else if ( isset($result->status) && strtolower($result->status) == 'failed') {
                CE_Lib::log(4, 'ERROR: ResellerClub domain transfer failed with error: ' . $result->actiontypedesc);
                throw new Exception('Error transfering ResellerClub domain: ' . $result->actiontypedesc);
            }
            $status = $result->{1}->actionstatusdesc;
            if ( $status == 'Domain Transfered Successfully.' ) {
                $userPackage->setCustomField('Transfer Status', 'Completed');
            }
            return $status;
        } else if ( isset($result->status) && strtolower($result->status) == 'failed') {
            CE_Lib::log(4, 'ERROR: ResellerClub domain transfer failed with error: ' . $result->actiontypedesc);
            throw new Exception('Error transfering ResellerClub domain: ' . $result->actiontypedesc);
        }
        $status = $result->{1}->actionstatusdesc;
        if ( $status == 'Domain Transfered Successfully.' ) {
            $userPackage->setCustomField('Transfer Status', 'Completed');
        }

        return $status;
    }


    function getContactInformation($params)
    {

        $params['sld'] = strtolower($params['sld']);
        $params['tld'] = strtolower($params['tld']);
        $domain = $params['sld'].".".$params['tld'];

        $domainId = $this->_lookupDomainId($domain);
        if (is_a($domainId, 'CE_Error')) {
            return $domainId;
        }

        $arguments = array(
            'order-id'      => $domainId,
            'options'       => 'RegistrantContactDetails'
        );

        $result = $this->_makeGetRequest('/domains/details', $arguments);

        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.');
        }
        if (isset($result->registrantcontact)) {
            $name = explode(' ', $result->registrantcontact->name, 2);

            $info = array();
            // some info might not be available when the privacy protection is enabled for the domain
            $info['Registrant']['OrganizationName']  = array($this->user->lang('Organization'), $result->registrantcontact->company);
            $info['Registrant']['FirstName'] = array($this->user->lang('First Name'), $name[0]);
            $info['Registrant']['LastName'] = array($this->user->lang('Last Name'), isset($name[1])? $name[1] : '');
            $info['Registrant']['Address1']  = array($this->user->lang('Address').' 1', $result->registrantcontact->address1);
            $info['Registrant']['Address2']  = array($this->user->lang('Address').' 2', isset($result->registrantcontact->address2)? $result->registrantcontact->address2 : '');
            $info['Registrant']['Address3']  = array($this->user->lang('Address').' 3', isset($result->registrantcontact->address3)? $result->registrantcontact->address3 : '');
            $info['Registrant']['City']      = array($this->user->lang('City'), $result->registrantcontact->city);
            $info['Registrant']['StateProv']  = array($this->user->lang('Province').'/'.$this->user->lang('State'), isset($result->registrantcontact->state)? $result->registrantcontact->state : '');
            $info['Registrant']['Country']   = array($this->user->lang('Country'), $result->registrantcontact->country);
            $info['Registrant']['PostalCode']  = array($this->user->lang('Postal Code').'/'.$this->user->lang('Zip'), $result->registrantcontact->zip);
            $info['Registrant']['EmailAddress']     = array($this->user->lang('E-mail'), $result->registrantcontact->emailaddr);
            $info['Registrant']['Phone']  = array($this->user->lang('Phone Country Code'), $result->registrantcontact->telnocc.$result->registrantcontact->telno);

            return $info;
        }
        if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub domain registrant contact fetch failed with error: ' . $result->message);
            throw new Exception('Error fetching ResellerClub domain registrant details.: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub domain registrant contact fetch failed with error');
            throw new Exception('Error fetching ResellerClub domain registrant details.');
        }
    }

    function setContactInformation($params)
    {
        $params['sld'] = strtolower($params['sld']);
        $params['tld'] = strtolower($params['tld']);
        $domain = $params['sld'].".".$params['tld'];

        $countrycode = $this->_getCountryCode($params['Registrant_Country']);
        $telno = $this->_validatePhone($params['Registrant_Phone'], $cc);

        $domainId = $this->_lookupDomainId($domain);
        if (is_a($domainId, 'CE_Error')) {
            return $domainId;
        }

        $arguments = array(
            'order-id'      => $domainId,
            'options'       => 'RegistrantContactDetails'
        );

        $result = $this->_makeGetRequest('/domains/details', $arguments);

        $contactId = 0;
        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.');
        }
        if (isset($result->registrantcontact)) {
            $contactId = $result->registrantcontact->contactid;
        } else if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub domain registrant contact fetch failed with error: ' . $result->message);
            throw new Exception('Error fetching ResellerClub domain registrant details.: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub domain registrant contact fetch failed with error');
            throw new Exception('Error fetching ResellerClub domain registrant details.');
        }

        if ($params['Registrant_OrganizationName'] == "") $params['Registrant_OrganizationName'] = "N/A";

        $arguments = array(
            'contact-id'            => $contactId,
            'name'                  => $params['Registrant_FirstName']." ".$params['Registrant_LastName'],
            'company'               => $params['Registrant_OrganizationName'],
            'email'                 => $params['Registrant_EmailAddress'],
            'address-line-1'        => $params['Registrant_Address1'],
            'address-line-2'        => $params['Registrant_Address2'],
            'address-line-3'        => $params['Registrant_Address3'],
            'city'                  => $params['Registrant_City'],
            'state'                 => $params['Registrant_StateProvince'],
            'country'               => $params['Registrant_Country'],
            'zipcode'               => $params['Registrant_PostalCode'],
            'phone-cc'              => $countrycode,
            'phone'                 => $telno,
        );

        $result = $this->_makePostRequest('/contacts/modify', $arguments);

        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.');
        }
        if (isset($result->actionstatus) && $result->actionstatus == 'Success') {
            CE_Lib::log(4, 'ResellerClub domain contact modified successfully.');
            return $return->actionstatusdesc;
        }
        if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub error while modifying domain contact details: ' . $result->message);
            throw new Exception('Error modifying domain contact details: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub error while modifying domain contact details.');
            throw new Exception('Error modifying domain contact details.');
        }

        return $ret2['actionstatusdesc'];
    }

    function getNameServers($params)
    {
        $params['sld'] = strtolower($params['sld']);
        $params['tld'] = strtolower($params['tld']);
        $domain = $params['sld'].".".$params['tld'];

        $domainId = $this->_lookupDomainId($domain);
        if (is_a($domainId, 'CE_Error')) {
            return $domainId;
        }

        $arguments = array(
            'order-id'      => $domainId,
            'options'       => 'NsDetails'
        );

        $result = $this->_makeGetRequest('/domains/details', $arguments);

        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.');
        }
        if (isset($result->classname)) {
            $i = 1;
            $ns = array();
            // There are no such thing as "default" name servers, with reseller club
            // Each reseller has their own branded name servers they must use.
            $ns['usesDefault'] = false;
            $ns['hasDefault'] = 0;
            $current = 'ns' . $i;
            while (isset($result->$current)) {
                $ns[] = $result->$current;
                $current = 'ns' . ++$i;
            }
            return $ns;
        }
        if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub get name servers failed with error: ' . $result->message);
            throw new Exception('Error fetching ResellerClub name servers.: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub get name servers failed with error');
            throw new Exception('Error fetching ResellerClub name servers.');
        }
    }

    function setNameServers($params)
    {
        $params['sld'] = strtolower($params['sld']);
        $params['tld'] = strtolower($params['tld']);
        $domain = $params['sld'].".".$params['tld'];

        $domainId = $this->_lookupDomainId($domain);
        if (is_a($domainId, 'CE_Error')) {
            return $domainId;
        }

        $ns = array();
        foreach ($params['ns'] as $value) {
            $ns[] = $value;
        }

        $arguments = array(
            'order-id'      => $domainId,
            'ns'            => $ns,
        );

        $result = $this->_makePostRequest('/domains/modify-ns', $arguments);

        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.');
        }

        if (isset($result->actionstatus) && $result->actionstatus == 'Success') {
            return;
        }

        if (isset($result->status) && $result->status == 'ERROR') {
            if ($result->message == 'Same value for new and old NameServers.') {
                CE_Lib::log(4, 'ResellerClub modify name servers for domain ' . $domain . ' resulted in no changes.');
                return;
            }
            CE_Lib::log(4, 'ERROR: ResellerClub modify name servers failed with error: ' . $result->message);
            throw new Exception('Error during ResellerClub modify name servers command.: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub modify name servers failed with error');
            throw new Exception('Error during ResellerClub modify name servers command.');
        }
        CE_Lib::log(4, 'ResellerClub modify name servers for domain ' . $domain . ' has been completed successfully.');
    }

    function registerNS($params)
    {
        $params['sld'] = strtolower($params['sld']);
        $params['tld'] = strtolower($params['tld']);
        $domain = $params['sld'].".".$params['tld'];

        $domainId = $this->_lookupDomainId($domain);
        if (is_a($domainId, 'CE_Error')) {
            return $domainId;
        }

        $arguments = array(
            'order-id'      => $domainId,
            'cns'           => $params['nsname'],
            'ip'            => $params['nsip'],
        );

        $result = $this->_makePostRequest('/domains/add-cns', $arguments);

        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.');
        }
        if (isset($result->actionstatus) && $result->actionstatus == 'Success') {
            CE_Lib::log(4, 'ResellerClub addition of child name server ' . $params['nsname'] . ' successful.');
            return $result->actiontypedesc;
        }
        if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub add child name servers failed with error: ' . $result->message);
            throw new Exception('Error during ResellerClub add child name servers command.: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub add child name servers failed with an error.');
            throw new Exception('Error during ResellerClub add child name servers command.');
        }
    }

    function editNS($params)
    {
        $params['sld'] = strtolower($params['sld']);
        $params['tld'] = strtolower($params['tld']);
        $domain = $params['sld'].".".$params['tld'];

        $domainId = $this->_lookupDomainId($domain);
        if (is_a($domainId, 'CE_Error')) {
            return $domainId;
        }

        $arguments = array(
            'order-id'      => $domainId,
            'cns'           => $params['nsname'],
            'old-ip'        => $params['nsoldip'],
            'new-ip'        => $params['nsnewip'],
        );

        $result = $this->_makePostRequest('/domains/modify-cns-ip', $arguments);

        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.');
        }
        if (isset($result->status) && $result->status == 'Success') {
            CE_Lib::log(4, 'ResellerClub modification of child name server ' . $params['nsname'] . ' successful.');
            return $result->actiontypedesc;
        }
        if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub modify child name servers failed with error: ' . $result->message);
            throw new Exception('Error during ResellerClub modify child name servers command.: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub modify child name servers failed with an error.');
            throw new Exception('Error during ResellerClub modify child name servers command.');
        }
    }

    function fetchDomains($params)
    {
        $page = 1;
        if ($params['next'] > 25) {
            $page = ceil($params['next'] / 25);
        }

        $arguments = array(
            'no-of-records'     => 100,
            'page-no'           => $page,
            'status'            => 'Active',
            'order-by'          => 'domainname',
        );

        $result = $this->_makeGetRequest('/domains/search', $arguments);

        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.');
        }
        if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub domain search failed with error: ' . $result->message);
            throw new Exception('Error during ResellerClub domain search command.: ' . $result->message);
        } else if (!isset($result->recsonpage)) {
            CE_Lib::log(4, 'ERROR: ResellerClub domain search failed with an error.');
            throw new Exception('Error during ResellerClub domain search command.');
        }

        $domainsList = array();
        $name = 'entity.description';
        $orderid = 'orders.orderid';
        $expiry = 'orders.endtime';
        for ($i = 1; $i <= $result->recsonpage; $i++) {
            CE_Lib::log(4, 'Working on domain: ' . $result->$i->$name);
            $dom = $this->splitDomain($result->$i->$name);
            $domainsList[] = array(
                'id'    => $result->$i->$orderid,
                'sld'   => $dom[0],
                'tld'   => $dom[1],
                'exp'   => date('m/d/Y', $result->$i->$expiry),
            );
        }
        $metaData = array();
        $metaData['total'] = $result->recsindb;
        $metaData['start'] = 1 + ($page - 1) * 25;
        $metaData['end'] = $page * 25;
        $metaData['next'] = $page * 25 + 1;
        $metaData['numPerPage'] = 25;
        CE_Lib::log(4, "Returing array of size: " . sizeof($domainsList));
        return array($domainsList, $metaData);
    }

    function deleteNS($params)
    {
        throw new Exception('This function is not supported');
    }

    function setAutorenew($params)
    {
        throw new MethodNotImplemented('This function is not supported');
    }

    function getRegistrarLock($params)
    {
        $params['sld'] = strtolower($params['sld']);
        $params['tld'] = strtolower($params['tld']);
        $domain = $params['sld'].".".$params['tld'];

        $domainId = $this->_lookupDomainId($domain);

        $arguments = array(
            'order-id'      => $domainId
        );

        $result = $this->_makeGetRequest('/domains/locks', $arguments);
        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.');
        }

        if (isset($result->transferlock)) {
            // transfer lock is enabled
            return $result->transferlock;
        } else if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub domain registrant contact fetch failed with error: ' . $result->message);
            throw new Exception('Error fetching ResellerClub domain registrant details.: ' . $result->message);
        } else {
            // empty result, means it's disabled
            return false;
        }
    }

    function doSetRegistrarLock($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->setRegistrarLock($this->buildLockParams($userPackage,$params));
        return "Updated Registrar Lock.";
    }

    function setRegistrarLock($params)
    {
        $params['sld'] = strtolower($params['sld']);
        $params['tld'] = strtolower($params['tld']);
        $domain = $params['sld'].".".$params['tld'];

        $domainId = $this->_lookupDomainId($domain);

        $arguments = array(
            'order-id'      => $domainId,
        );

        if ( $params['lock'] == true ) {
            $url = '/domains/enable-theft-protection';
        } else {
            $url = '/domains/disable-theft-protection';
        }
        $result = $this->_makePostRequest($url, $arguments);
        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.');
        }

        if (isset($result->status) && $result->status == 'Success') {
            return;
        } else if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub domain registrant contact fetch failed with error: ' . $result->message);
            throw new Exception('Error fetching ResellerClub domain registrant details.: ' . $result->message);
        } else {
            CE_Lib::log(4, 'ERROR: ResellerClub domain registrant contact fetch failed with error');
            throw new Exception('Error fetching ResellerClub domain registrant details.');
        }
    }

    function sendTransferKey($params)
    {
        throw new Exception('This function is not supported');
    }

    function getDNS($params)
    {
        throw new Exception('Getting DNS Records is not supported in this plugin.', EXCEPTION_CODE_NO_EMAIL);
    }

    function setDNS($params)
    {
        return true;
    }

    function hasPrivacyProtection($contactInfo)
    {
        return ($contactInfo['OrganizationName'][1] == 'PrivacyProtect.org');
    }

    function _makeGetRequest($servlet, $arguments)
    {
        return $this->_makeRequest($servlet, $arguments, false);
    }

    function _makePostRequest($servlet, $arguments)
    {
        return $this->_makeRequest($servlet, $arguments, true);
    }


    function _makeRequest($servlet, $arguments, $isPost = false)
    {
        $arguments['auth-userid'] = $this->settings->get('plugin_resellerclub_Reseller ID');
        if ( $this->settings->get('plugin_resellerclub_API Key') != '' ) {
           $arguments['api-key'] = $this->settings->get('plugin_resellerclub_API Key');
        } else {
           $arguments['auth-password'] = $this->settings->get('plugin_resellerclub_Password');
        }

        $request = 'https://';
        if (@$this->settings->get('plugin_resellerclub_Use testing server')) $request .= 'test.';
        $request .= 'httpapi.com/api';
        $request .= $servlet . '.json';

        CE_Lib::log(4, 'Parsing arguments.');

        $data = '';
        foreach ($arguments as $name => $value) {
            $name = urlencode($name);
            if (is_array($value)) {
                // Need to handle arrays
                foreach ($value as $multivalue) {
                    if ($multivalue === true) $multivalue = 'true';
                    else if ($multivalue === false) $multivalue = 'false';
                    $data .= $name . '=' . urlencode($multivalue) . '&';
                }
            } else {
                if ($value === true) $value = 'true';
                else if ($value === false) $value = 'false';
                $data .= $name . '=' . urlencode($value) . '&';
            }
        }

        $postData = false;
        if ($isPost) {
            $postData = $data;
        } else {
            $request .= '?' . $data;
        }

        CE_Lib::log(4, 'ResellerClub request: ' . $request);

        // certificate validation doesn't work well under windows
		$requestType = ($isPost) ? 'POST' : 'GET';
        $response = NE_Network::curlRequest($this->settings, $request, $postData, false, true, false, $requestType);

        CE_Lib::log(4, 'ResellerClub response: ' . $response);

        if (is_a($response, 'CE_Error')) {
           CE_Lib::log(4, 'Error communicating with ResellerClub: ' . $response->getMessage());
           throw new Exception('Error communicating with ResellerClub: ' . $response->getMessage());
        } else if (!$response) {
            CE_Lib::log(4, 'Error communicating with ResellerClub: No response found.');
            throw new Exception('Error communicating with ResellerClub: No response found.');
        }

        return json_decode($response);
    }

    function _lookupCustomerId($email)
    {
        $arguments = array(
            'username' => $email,
        );
        $result = $this->_makeGetRequest('/customers/details', $arguments);
		if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.');
        }

        if (isset($result->customerid) && $result->customerid > 0) {
            CE_Lib::log(4, 'ResellerClub customer "' . $email . '" already exists: ' . $result->customerid);
            return $result->customerid;
        }
        CE_Lib::log(4, 'ResellerClub customer "' . $email . '" does not already exist.');
        return false;
    }

    function _lookupDomainId($domain)
    {
        $arguments = array(
            'domain-name' => $domain,
        );
        $result = $this->_makeGetRequest('/domains/orderid', $arguments);
        if ($result === false) {
            throw new Exception('A connection issued occurred while connecting to ResellerClub.', EXCEPTION_CODE_CONNECTION_ISSUE);
        }
        if (is_numeric($result)) {
            CE_Lib::log(4, 'ResellerClub domain id "' . $result . '" found for domain ' . $domain . '.');
            return $result;
        }
        if (isset($result->status) && $result->status == 'ERROR') {
            CE_Lib::log(4, 'ERROR: ResellerClub error occurred while looking up domain id for ' . $domain . '.  Error: ' . $result->message);
            throw new CE_Exception('An error occurred while connecting to ResellerClub.  Error: ' . $result->message);
        }
        CE_Lib::log(4, 'ERROR: ResellerClub error occurred while looking up domain id for ' . $domain . '.  Error: Unknown Error.');
        throw new Exception('An error occurred while connecting to ResellerClub.  Error: Unknown');
    }

    function _getCountryCode($country)
    {
        $query = "SELECT `phone_code` FROM `country` WHERE `iso`=? AND phone_code != ''";
        $result = $this->db->query($query, $country);
        $row = $result->fetch();
        return $row['phone_code'];
    }

    function _validatePhone($phone, $code)
    {
        // strip all non numerical values
        $phone = preg_replace('/[^\d]/', '', $phone);
        // check if code is already there and delete it
        return preg_replace("/^($code)(\\d+)/", '\2', $phone);
    }

    // For developer use only.
    // Helper function so we can easily regenerate the extra domain attributes
    // required for .us domains.  Others could be added later.
    function _generateExtraDomainAttributes()
    {
        $dotUs = array();

        $nexus_purpose = array(
            'ID'          => 1,
            'description' => 'Nexus Purpose',
            'options'     => array(
                'For Profit'       => array(
                    'description' => 'Business use for profit.',
                    'value' => 'P1'
                ),
                'Non-profit'       => array(
                    'description' => 'Non-profit business, club, association, religious organization, etc.',
                    'value' => 'P2'
                ),
                'Personal'       => array(
                    'description' => 'Personal use.',
                    'value' => 'P3'
                ),
                'Educational'       => array(
                    'description' => 'Education purposes.',
                    'value' => 'P4'
                ),
                'Government'       => array(
                    'description' => 'Government purposes.',
                    'value' => 'P5'
                ),
            )
        );

        $dotUs['purpose'] = $nexus_purpose;

        $nexus_category = array(
            'ID'          => 2,
            'description' => 'Nexus Category',
            'options'     => array(
                'US Citizen'       => array(
                    'description' => 'A natural person who is a United States citizen.',
                    'value' => 'C11'
                ),
                'Permanent Resident'       => array(
                    'description' => 'A natural person who is a permanent resident of the United States of America, or any of its possessions or territories.',
                    'value' => 'C12'
                ),
                'Business Entity'       => array(
                    'description' => 'A US-based organization or company (A US-based organization or company formed within one of the fifty (50) U.S. states, the District of Columbia, or any of the United States possessions or territories, or organized or otherwise constituted under the laws of a state of the United States of America, the District of Columbia or any of its possessions or territories or a U.S. federal, state, or local government entity or a political subdivision thereof).',
                    'value' => 'C21'
                ),
                'Foreign Entity'       => array(
                    'description' => 'A foreign entity or organization (A foreign entity or organization that has a bona fide presence in the United States of America or any of its possessions or territories who regularly engages in lawful activities (sales of goods or services or other business, commercial or non-commercial, including not-for-profit relations in the United States)).',
                    'value' => 'C31'
                ),
                'US Based Office'       => array(
                    'description' => 'Entity has an office or other facility in the United States.',
                    'value' => 'C32'
                ),
            )
        );

        $dotUs['category'] = $nexus_category;

        return serialize($dotUs);
    }

    function disableRenewal ($params)
    {
        throw new Exception('Method disableRenewal() was not implemented yet.');
    }

    function checkNSStatus ($params)
    {
        throw new Exception('Method checkNSStatus() was not implemented yet.');
    }

    function splitDomain($domain)
    {
        if (($position = strpos($domain, '.')) === false) {
            return array($domain, '');
        }
        return array(mb_substr($domain, 0, $position), mb_substr($domain, $position + 1));
    }

    function disablePrivateRegistration($parmas)
    {
        throw new MethodNotImplemented('Method disablePrivateRegistration has not been implemented yet.');
    }

    private function getAdminContactId($tld, $contactId)
    {
        switch ( $tld ) {
            case 'eu':
            case 'nz':
            case 'ru':
            case 'uk':
            case 'co.uk':
            case 'org.uk':
            case 'me.uk':
                return -1;
        }
        return $contactId;
    }

    private function getTechContactId($tld, $contactId)
    {
        // Tech & Admin have the same restrictions.
        return $this->getAdminContactId($tld, $contactId);
    }

    private function getBillingContactId($tld, $contactId)
    {
        switch ( $tld ) {
            case 'berlin':
            case 'ca':
            case 'eu':
            case 'nl':
            case 'nz':
            case 'ru':
            case 'co.uk':
            case 'org.uk':
            case 'me.uk':
            case 'uk':
                return -1;
        }
        return $contactId;
    }

    private function getContactType($params)
    {
        switch($params['tld']) {
            case 'ca':
                $contactType = 'CaContact';
                break;
            case 'cn':
                $contactType = 'CnContact';
                break;
            case 'co':
                $contactType = 'CoContact';
                break;
            case 'de':
                $contactType = 'DeContact';
                break;
            case 'es':
                $contactType = 'EsContact';
                break;
            case 'eu':
                $contactType = 'EuContact';
                break;
            case 'ru':
                $contactType = 'RuContact';
                break;
            case 'co.uk':
            case 'org.uk':
            case 'me.uk':
                $contactType = 'UkContact';
                break;
            case 'coop':
                $contactType = 'CoopContact';
                break;
            default:
                $contactType = 'Contact';
                break;
        }
        return $contactType;
    }
}
