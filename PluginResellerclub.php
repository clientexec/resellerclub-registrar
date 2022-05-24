<?php
require_once 'modules/admin/models/RegistrarPlugin.php';
require_once 'library/CE/NE_Network.php';

use Clientexec\Registrars\LogicBoxes;

class PluginResellerclub extends RegistrarPlugin
{
    public $features = [
        'nameSuggest' => true,
        'importDomains' => true,
        'importPrices' => true,
    ];

    private $recordTypes = ['A', 'AAAA', 'MX', 'CNAME', 'TXT'];
    private $api;

    public function __construct($user)
    {
        parent::__construct($user);

        $this->api = new LogicBoxes(
            $this->settings->get('plugin_resellerclub_Reseller ID'),
            $this->settings->get('plugin_resellerclub_API Key'),
            $this->settings->get('plugin_resellerclub_Password'),
            $this->user,
            $this->recordTypes,
            $this->settings->get('plugin_resellerclub_Use testing server')
        );
    }

    function getVariables()
    {
        $variables = [
            lang('Plugin Name') => [
                'type' => 'hidden',
                'description' => lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                'value' => lang('ResellerClub')
            ],
            lang('Use testing server') => [
                'type' => 'yesno',
                'description' => lang('Select Yes if you wish to use the ResellerClub testing environment, so that transactions are not actually made.<br><br><b>Note: </b>You will first need to register for a demo account at<br>http://cp.onlyfordemo.net/servlet/ResellerSignupServlet?&validatenow=false.'),
                'value' => 0
            ],
            lang('Reseller ID') => [
                'type' => 'text',
                'description' => lang('Enter your ResellerClub Reseller ID.  This can be found in your ResellerClub    account by going to your profile link, in the top right corner.'),
                'value' => ''
            ],
            lang('Password') => [
                'type' => 'password',
                'description'  => lang('Enter the password for your ResellerClub reseller account.'),
                'value' => ''
            ],
            lang('API Key') => [
                'type' => 'text',
                'description' => lang('Enter your API Key for your ResellerClub reseller account.  You should use this instead of your password, however you still may use your password instead.'),
                'value' => ''
            ],
            lang('Supported Features')  => [
                'type' => 'label',
                'description' => '* '.lang('TLD Lookup').'<br>* '.lang('Domain Registration').' <br>* '.lang('Domain Registration with ID Protect').' <br>* '.lang('Existing Domain Importing').' <br>* '.lang('Get / Set Nameserver Records').' <br>* '.lang('Get / Set Contact Information').' <br>* '.lang('Get / Set Registrar Lock').' <br>* '.lang('Initiate Domain Transfer').' <br>* ' . lang('Automatically Renew Domain'),
                'value' => ''
            ],
            lang('Actions') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
                'value' => 'Register'
            ],
            lang('Registered Actions') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value' => 'Renew (Renew Domain),DomainTransferWithPopup (Initiate Transfer),Cancel',
            ],
            lang('Registered Actions For Customer') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value' => '',
            ]
        ];

        return $variables;
    }

    public function getTLDsAndPrices($params)
    {
        return $this->api->getPrices($params);
    }

    function checkDomain($params)
    {
        return $this->api->checkDomain($params);
    }


    function doTogglePrivacy($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $status = $this->togglePrivacy($this->buildRegisterParams($userPackage, $params));
        return "Turned privacy {$status} for " . $userPackage->getCustomField('Domain Name') . '.';
    }

    function togglePrivacy($params)
    {
        return $this->api->togglePrivacy($params);
    }

    /**
     * Register domain name
     *
     * @param array $params
     */
    function doRegister($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->registerDomain($this->buildRegisterParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar").'-'.$orderid[1][0]);
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
        $orderid = $this->renewDomain($this->buildRenewParams($userPackage, $params));
        return $userPackage->getCustomField('Domain Name') . ' has been renewed.';
    }


    function registerDomain($params)
    {
        $params['countrycode'] = $this->getCountryCode($params['RegistrantCountry']);
        return $this->api->registerDomain($params);
    }

    function renewDomain($params)
    {
        return $this->api->renewDomain($params);
    }

    function getGeneralInfo($params)
    {
        return $this->api->getGeneralInfo($params);
    }

    /**
     * Initiate a domain transfer
     *
     * @param array $params
     */
    function doDomainTransferWithPopup($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $transferid = $this->initiateTransfer($this->buildTransferParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar").'-'.$transferid);
        $userPackage->setCustomField('Transfer Status', $transferid);
        return "Transfer of has been initiated.";
    }

    function initiateTransfer($params)
    {
        $params['countrycode'] = $this->getCountryCode($params['RegistrantCountry']);
        return $this->api->initiateTransfer($params);
    }

    function getTransferStatus($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);

        $status = $this->api->getTransferStatus($params, $userPackage->getCustomField('Transfer Status'));
        if ($status == 'Domain Transferred Successfully.') {
            $userPackage->setCustomField('Transfer Status', 'Completed');
        }
        return $status;
    }

    function getContactInformation($params)
    {
        return $this->api->getContactInformation($params);
    }

    function setContactInformation($params)
    {
        $params['countrycode'] = $this->getCountryCode($params['Registrant_Country']);
        return $this->api->setContactInformation($params);
    }

    function getNameServers($params)
    {
        return $this->api->getNameServers($params);
    }

    function setNameServers($params)
    {
        $this->api->setNameServers($params);
    }

    // function registerNS($params)
    // {
    //     $params['sld'] = strtolower($params['sld']);
    //     $params['tld'] = strtolower($params['tld']);
    //     $domain = $params['sld'].".".$params['tld'];

    //     $domainId = $this->_lookupDomainId($domain);
    //     if (is_a($domainId, 'CE_Error')) {
    //         return $domainId;
    //     }

    //     $arguments = array(
    //         'order-id'      => $domainId,
    //         'cns'           => $params['nsname'],
    //         'ip'            => $params['nsip'],
    //     );

    //     $result = $this->_makePostRequest('/domains/add-cns', $arguments);

    //     if ($result === false) {
    //         throw new Exception('A connection issued occurred while connecting to ResellerClub.');
    //     }
    //     if (isset($result->actionstatus) && $result->actionstatus == 'Success') {
    //         CE_Lib::log(4, 'ResellerClub addition of child name server ' . $params['nsname'] . ' successful.');
    //         return $result->actiontypedesc;
    //     }
    //     if (isset($result->status) && $result->status == 'ERROR') {
    //         CE_Lib::log(4, 'ERROR: ResellerClub add child name servers failed with error: ' . $result->message);
    //         throw new Exception('Error during ResellerClub add child name servers command.: ' . $result->message);
    //     } else {
    //         CE_Lib::log(4, 'ERROR: ResellerClub add child name servers failed with an error.');
    //         throw new Exception('Error during ResellerClub add child name servers command.');
    //     }
    // }

    // function editNS($params)
    // {
    //     $params['sld'] = strtolower($params['sld']);
    //     $params['tld'] = strtolower($params['tld']);
    //     $domain = $params['sld'].".".$params['tld'];

    //     $domainId = $this->_lookupDomainId($domain);
    //     if (is_a($domainId, 'CE_Error')) {
    //         return $domainId;
    //     }

    //     $arguments = array(
    //         'order-id'      => $domainId,
    //         'cns'           => $params['nsname'],
    //         'old-ip'        => $params['nsoldip'],
    //         'new-ip'        => $params['nsnewip'],
    //     );

    //     $result = $this->_makePostRequest('/domains/modify-cns-ip', $arguments);

    //     if ($result === false) {
    //         throw new Exception('A connection issued occurred while connecting to ResellerClub.');
    //     }
    //     if (isset($result->status) && $result->status == 'Success') {
    //         CE_Lib::log(4, 'ResellerClub modification of child name server ' . $params['nsname'] . ' successful.');
    //         return $result->actiontypedesc;
    //     }
    //     if (isset($result->status) && $result->status == 'ERROR') {
    //         CE_Lib::log(4, 'ERROR: ResellerClub modify child name servers failed with error: ' . $result->message);
    //         throw new Exception('Error during ResellerClub modify child name servers command.: ' . $result->message);
    //     } else {
    //         CE_Lib::log(4, 'ERROR: ResellerClub modify child name servers failed with an error.');
    //         throw new Exception('Error during ResellerClub modify child name servers command.');
    //     }
    // }

    function fetchDomains($params)
    {
        $result = $this->api->fetchDomains($params);
        $domainNameGateway = new DomainNameGateway();

        $domainsList = [];
        $name = 'entity.description';
        $orderid = 'orders.orderid';
        $expiry = 'orders.endtime';
        for ($i = 1; $i <= $result->recsonpage; $i++) {
            $domain = $domainNameGateway->splitDomain($result->$i->$name);
            $domainsList[] = [
                'id' => $result->$i->$orderid,
                'sld' => $domain[0],
                'tld' => $domain[1],
                'exp' => date('m/d/Y', $result->$i->$expiry),
            ];
        }
        $metaData = [];
        $metaData['total'] = $result->recsindb;
        $metaData['start'] = 1 + ($page - 1) * 25;
        $metaData['end'] = $page * 25;
        $metaData['next'] = $page * 25 + 1;
        $metaData['numPerPage'] = 25;
        return [$domainsList, $metaData];
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
        return $this->api->getRegistrarLock($params);
    }

    function doSetRegistrarLock($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->setRegistrarLock($this->buildLockParams($userPackage, $params));
        return "Updated Registrar Lock.";
    }

    function setRegistrarLock($params)
    {
        $this->api->setRegistrarLock($params);
    }

    function sendTransferKey($params)
    {
        throw new Exception('This function is not supported');
    }

    function getDNS($params)
    {
        $records = $this->api->getDNS($params, $this->recordTypes);
        return array('records' => $records, 'types' => $this->recordTypes, 'default' => true);
    }

    function setDNS($params)
    {
        $this->api->setDNS($params);
    }

    function getEPPCode($params)
    {
        $info = $this->api->getGeneralInfo($params);
        return $info['domsecret'];
    }

    function hasPrivacyProtection($contactInfo)
    {
        return ($contactInfo['OrganizationName'][1] == 'PrivacyProtect.org');
    }

    function disableRenewal($params)
    {
        throw new Exception('Method disableRenewal() was not implemented yet.');
    }

    function checkNSStatus($params)
    {
        throw new Exception('Method checkNSStatus() was not implemented yet.');
    }

    function disablePrivateRegistration($parmas)
    {
        throw new MethodNotImplemented('Method disablePrivateRegistration has not been implemented yet.');
    }

    private function getCountryCode($country)
    {
        $query = "SELECT `phone_code` FROM `country` WHERE `iso`=? AND phone_code != ''";
        $result = $this->db->query($query, $country);
        $row = $result->fetch();
        return $row['phone_code'];
    }
}
