<?php
/**
* @version 1.0.0
* @package RSEvents!Pro 1.0.0
* Copyright (c) 2008 PayFast (Pty) Ltd
 * You (being anyone who is not PayFast (Pty) Ltd) may download and use this plugin / code in your own website in conjunction with a registered and active PayFast account. If your PayFast account is terminated for any reason, you may not use this plugin / code or part thereof.
 * Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code or part thereof in any way.
*/
defined( '_JEXEC' ) or die( 'Restricted access' );
jimport( 'joomla.plugin.plugin' );

class plgSystemRsepropayfast extends JPlugin
{
    //set the value of the payment option
    var $rsprooption = 'payfast';

    const SANDBOX_MERCHANT_KEY = '1pelravrwmo8e';
    const SANDBOX_MERCHANT_ID = '10000861';
    
    public function __construct( &$subject, $config ) {
        parent::__construct( $subject, $config );
    }
    
    public function onAfterInitialise() {       
        $app    = JFactory::getApplication();
        $jinput = $app->input;
        if($app->getName() != 'site') 
        {
            return;
        }

        $payfast = $jinput->getInt('payfastitn');
        
        if (!empty($payfast))
        {
            $this->rsepro_processITN(array());
        }
            
    }
    
    /*
    *   Is RSEvents!Pro installed
    */
    
    protected function canRun() {
        $helper = JPATH_SITE.'/components/com_rseventspro/helpers/rseventspro.php';
        if (file_exists($helper)) {
            require_once $helper;
            JFactory::getLanguage()->load('plg_system_rsepropayfast',JPATH_ADMINISTRATOR);
            
            return true;
        }
        
        return false;
    }
    
    /*
    *   Add the current payment option to the Payments List
    */

    public function rsepro_addOptions() {
        if ($this->canRun())
            return JHTML::_('select.option', $this->rsprooption, JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_NAME'));
        else return JHTML::_('select.option', '', '');
    }
    
   /*
    *   Add optional fields for the payment plugin. Example: Credit Card Number, etc.
    *   Please use the syntax <form method="post" action="index.php?option=com_rseventspro&task=process" name="paymentForm">
    *   The action provided in the form will actually run the rsepro_processForm() of your payment plugin.
    */
    
    public function rsepro_showForm($vars) {
        $app =& JFactory::getApplication();     
        if($app->getName() != 'site') return;
        
        //check to see if we can show something
        if (!$this->canRun()) return;
        
        if (isset($vars['method']) && $vars['method'] == $this->rsprooption) {
            JFactory::getLanguage()->load('com_rseventspro',JPATH_SITE);
        
            jimport('joomla.mail.helper');
            $db     = JFactory::getDbo();
            $query  = $db->getQuery(true);
            
            //is the plugin enabled ?
            $enable = JPluginHelper::isEnabled('system', 'rsepropayfast');
            if (!$enable) return;
            
            $details = $vars['details'];
            $tickets = $vars['tickets'];
            
            //check to see if its a payment request
            if (empty($details->verification) && empty($details->ide) && empty($details->email) && empty($tickets)) 
                return;
            
            //get the currency
            $currency = $vars['currency'];
            
            //get payfast details
            $payfast_merchantKey      = $this->params->get('payfast_merchantKey');
            $payfast_merchantId      = $this->params->get('payfast_merchantId');
            $payfast_return     = $this->params->get('payfast_return_url','');
            $payfast_cancel     = $this->params->get('payfast_cancel_url');
            //get the url for payfast
            $payfast_url = 'https://'.($this->params->get( 'payfast_mode' ) ? 'www' : 'sandbox').'.payfast.co.za/eng/process' ;
            
            $query->clear()
                ->select($db->qn('name'))
                ->from($db->qn('#__rseventspro_events'))
                ->where($db->qn('id').' = '.(int) $details->ide);
            
            $db->setQuery($query);
            $event = $db->loadObject();
            
            //do we allow users to sell their own tickets?
            if (rseventsproHelper::getConfig('payment_payfast','int')) {
                $query->clear()
                    ->select($db->qn('payfast_merchantId'))
                    ->from($db->qn('#__rseventspro_events'))
                    ->where($db->qn('id').' = '.(int) $details->ide);
                
                $db->setQuery($query);
                $user_payfast = $db->loadResult();
                
                if (!empty($user_payfast)) 
                    $payfast_merchantId = $user_payfast;

                $query->clear()
                    ->select($db->qn('payfast_merchantKey'))
                    ->from($db->qn('#__rseventspro_events'))
                    ->where($db->qn('id').' = '.(int) $details->ide);
                
                $db->setQuery($query);
                $user_payfast = $db->loadResult();
                
                if (!empty($user_payfast)) 
                    $payfast_merchantKey = $user_payfast;
            }
            
            //check to see if the return_url is valid
            if (substr($payfast_return,0,4) != 'http') 
                $payfast_return = '';         
            
            
            if (count($tickets) == 1) {
                $ticket         = $tickets[0];
                $payfast_item   = htmlentities($event->name.' - '.$ticket->name,ENT_QUOTES,'UTF-8');
                $payfast_total  = isset($ticket->price) ? $ticket->price : 0;
                $payfast_number = isset($ticket->quantity) ? $ticket->quantity : 1;
            } else {
                $payfast_item   = htmlentities($event->name.' - '.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_MULTIPLE'),ENT_QUOTES,'UTF-8');
                $payfast_total  = 0;
                $payfast_number = 1;
                
                foreach ($tickets as $ticket) {
                    if ($ticket->price > 0)
                        $payfast_total += ($ticket->price * $ticket->quantity);
                }
            }
            
            if ($payfast_total == 0) return;
            
            $thetax = 0;
            $thediscount = 0;
            
            if ($details->early_fee)
                $thediscount += $details->early_fee;
            
            if ($details->late_fee)
                $thetax += $details->late_fee;
            
            $html = '';     
            $html .= '<fieldset>'."\n";
            $html .= '<legend>'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_TICKETS_INFO').'</legend>'."\n";
            $html .= '<table cellspacing="10" cellpadding="0" class="table table-bordered rs_table">'."\n";
            $html .= '<tr>'."\n";
            $html .= '<td>'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_TICKETS').'</td>'."\n";
            $html .= '<td>'."\n";
            
            $discount = $details->discount;
            $total = 0;
            if (!empty($tickets)) { 
                foreach ($tickets as $ticket) {
                    if (empty($ticket->price))
                        $html .= $ticket->quantity. ' x '.$ticket->name.' ('.JText::_('COM_RSEVENTSPRO_GLOBAL_FREE'). ')<br />';
                    else
                        $html .= $ticket->quantity. ' x '.$ticket->name.' ('.rseventsproHelper::currency($ticket->price). ')<br />';
                    
                    if ($ticket->price > 0)
                        $total += ($ticket->quantity * $ticket->price);
                }
            } 
            if (!empty($discount)) $total = $total - $discount;
            
            $html .= '</td>'."\n";
            $html .= '</tr>'."\n";
            
            if (!empty($discount)) {
                $html .= '<tr>'."\n";
                $html .= '<td>'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_TICKETS_DISCOUNT').'</td>'."\n";
                $html .= '<td>'.rseventsproHelper::currency($discount).'</td>'."\n";
                $html .= '</tr>'."\n";
            }
            
            if ($details->early_fee) {
                $total = $total - $details->early_fee;
                $html .= '<tr>'."\n";
                $html .= '<td>'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_EARLY_FEE').'</td>'."\n";
                $html .= '<td>'."\n";
                $html .= rseventsproHelper::currency($details->early_fee);
                $html .= '</td>'."\n";
                $html .= '</tr>'."\n";
            }
            
            if ($details->late_fee) {
                $total = $total + $details->late_fee;
                $html .= '<tr>'."\n";
                $html .= '<td>'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_LATE_FEE').'</td>'."\n";
                $html .= '<td>'."\n";
                $html .= rseventsproHelper::currency($details->late_fee);
                $html .= '</td>'."\n";
                $html .= '</tr>'."\n";
            }
            
            if (!empty($details->tax)) {
                $total = $total + $details->tax;
                $html .= '<tr>'."\n";
                $html .= '<td>'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_TICKETS_TAX').'</td>'."\n";
                $html .= '<td>'.rseventsproHelper::currency($details->tax).'</td>'."\n";
                $html .= '</tr>'."\n";
            }
            
            $html .= '<tr>'."\n";
            $html .= '<td>'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_TICKETS_TOTAL').'</td>'."\n";
            $html .= '<td>'.rseventsproHelper::currency($total).'</td>'."\n";
            $html .= '</tr>'."\n";
            
            $html .= '</table>'."\n";
            $html .= '</fieldset>'."\n";
            
            $html .= '<p style="margin: 10px;font-weight: bold;">'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_REDIRECTING').'</p>'."\n";
            
            $html .= '<form method="post" action="'.$payfast_url.'" id="payfastForm">'."\n";


            $payFastFormArray = array();
            if( $this->params->get('payfast_mode') )
            {
                $payFastFormArray['merchant_id'] = $payfast_merchantId;
                $payFastFormArray['merchant_key'] = $payfast_merchantKey;
            }
            else
            {
                $payFastFormArray['merchant_id'] = self::SANDBOX_MERCHANT_ID;
                $payFastFormArray['merchant_key'] = self::SANDBOX_MERCHANT_KEY;
            }
            

            $payFastFormArray['return_url'] = $this->escape($payfast_return);
            $payFastFormArray['cancel_url'] = $this->escape($payfast_cancel);
            $payFastFormArray['notify_url'] = JRoute::_(JURI::root().'index.php?payfastitn=1');
        
            $payFastFormArray['m_payment_id'] = $this->escape($details->verification);
            $payFastFormArray['amount'] = $this->convertprice($total);
            $payFastFormArray['item_name'] = $payfast_item;       
            $payFastFormArray['custom_str1'] = $this->escape($details->verification);           

            foreach( $payFastFormArray  as $key => $val )
            {
                $pfOutput .= $key .'='. urlencode( $val ) .'&';
            }
            
    
            // Remove last ampersand
            $pfOutput = substr( $pfOutput, 0, -1 );

            $payFastFormArray['signature'] = md5( $pfOutput );

            foreach( $payFastFormArray  as $key => $val )
            {
                $html .= "<input type='hidden' value='$val' name='$key'>";
            }           
            $html .= '</form>'."\n";
            
            $html .= '<script type="text/javascript">'."\n";
            $html .= 'function payfastFormSubmit() { document.getElementById(\'payfastForm\').submit() }'."\n";
            $html .= 'try { window.addEventListener ? window.addEventListener("load",payfastFormSubmit,false) : window.attachEvent("onload",payfastFormSubmit); }'."\n";
            $html .= 'catch (err) { payfastFormSubmit(); }'."\n";
            $html .= '</script>'."\n";
           
            echo $html;         
        }
        
    }
    
    /*
    *   PayFast ITN callback function
    */
    
    public function rsepro_processITN($vars) {
            
        
        //check to see if we can show something
        if (!$this->canRun()) 
            return;
        
        $db     = JFactory::getDbo();
        $app    = JFactory::getApplication();
        $jinput = $app->input;
        $query  = $db->getQuery(true);
        $log    = array();
        $params = array();

        // Variable Initialization
        $pfError = false;
        $pfErrMsg = '';
        $pfDone = false;
        $pfData = array();
        $pfOrderId = '';
        $pfParamString = '';

        //// Notify PayFast that information has been received
        if( !$pfError && !$pfDone )
        {
            header( 'HTTP/1.0 200 OK' );
            flush();
        }

        // assign posted variables to local variables
        $custom = $jinput->getString('custom_str1');
        if (empty($custom)) return;

        define( 'PF_DEBUG', $this->params->get('payfast_debug') );
        include( JPATH_PLUGINS .'/system/rsepropayfast/payfast_common.inc');
        
        $query->clear()
            ->select($db->qn('id'))
            ->from($db->qn('#__rseventspro_users'))
            ->where($db->qn('verification').' = '.$db->q($custom));
        
        $db->setQuery($query);
        $subscriber = $db->loadResult();
        
        $query->clear()
            ->select($db->qn('state'))
            ->from($db->qn('#__rseventspro_users'))
            ->where($db->qn('id').' = '.(int) $subscriber);
        
        $db->setQuery($query);
        $state = $db->loadResult();
        
        if ($state == 1) 
            return;
        
        $query->clear()
            ->select($db->qn('gateway'))
            ->from($db->qn('#__rseventspro_users'))
            ->where($db->qn('id').' = '.(int) $subscriber);
        
        $db->setQuery($query);
        $gateway = $db->loadResult();
        
        if ($gateway != $this->rsprooption)
            return;
        


        if(!empty($subscriber)) {
            $query->clear()
                ->select($db->qn('t.price'))->select($db->qn('ut.quantity'))
                ->from($db->qn('#__rseventspro_user_tickets','ut'))
                ->join('left', $db->qn('#__rseventspro_tickets','t').' ON '.$db->qn('t.id').' = '.$db->qn('ut.idt'))
                ->where($db->qn('ut.ids').' = '.(int) $subscriber);
            
            $db->setQuery($query);
            $tickets = $db->loadObjectList();
            
            $total_price = 0;
            if (!empty($tickets)) {
                foreach ($tickets as $ticket) {             
                    if ($ticket->price > 0)
                        $total_price += ($ticket->quantity * $ticket->price);
                }
            }
            
            $query->clear()
                ->select($db->qn('discount'))->select($db->qn('early_fee'))
                ->select($db->qn('late_fee'))->select($db->qn('tax'))
                ->from($db->qn('#__rseventspro_users'))
                ->where($db->qn('id').' = '.(int) $subscriber);
            
            $db->setQuery($query);
            $details = $db->loadObject();
            
            // check if the amount is correct
            if (!empty($details->discount)) 
                $total = $total_price - $details->discount; 
            else $total = $total_price;
            
            if (!empty($details->early_fee))
                $total = $total - $details->early_fee;
            
            if (!empty($details->late_fee))
                $total = $total + $details->late_fee;
            
            //add tax
            if (!empty($details->tax))
                $total = $total + $details->tax;                            
                              

            //// Get data sent by PayFast
            if( !$pfError && !$pfDone )
            {
                pflog( 'Get posted data' );
            
                // Posted variables from ITN
                $pfData = pfGetData();
                $payfast_data = $pfData;
            
                pflog( 'PayFast Data: '. print_r( $pfData, true ) );
            
                if( $pfData === false )
                {
                    $pfError = true;
                    $pfErrMsg = PF_ERR_BAD_ACCESS;
                }
            }
        
            $pfHost = ( $this->params->get('payfast_mode') ? 'www' : 'sandbox' ) . '.payfast.co.za';
            
            pflog( 'PayFast ITN call received' );
            
            //// Verify security signature
            if( !$pfError && !$pfDone )
            {
                pflog( 'Verify security signature' );
            
                // If signature different, log for debugging
                if( !pfValidSignature( $pfData, $pfParamString ) )
                {
                    $pfError = true;
                    $pfErrMsg = PF_ERR_INVALID_SIGNATURE;
                }
            }
    
            //// Verify source IP (If not in debug mode)
            if( !$pfError && !$pfDone )
            {
                pflog( 'Verify source IP' );
            
                if( !pfValidIP( $_SERVER['REMOTE_ADDR'] ) )
                {
                    $pfError = true;
                    $pfErrMsg = PF_ERR_BAD_SOURCE_IP;
                }
            }
    
            //// Verify data received
            if( !$pfError )
            {
                pflog( 'Verify data received' );
            
                $pfValid = pfValidData( $pfHost, $pfParamString );
            
                if( !$pfValid )
                {
                    $pfError = true;
                    $pfErrMsg = PF_ERR_BAD_ACCESS;
                }
            }
            
            //// Check data against internal order
            if( !$pfError && !$pfDone )
            {
               // pflog( 'Check data against internal order' );
        
                // Check order amount
                if( !pfAmountsEqual( $pfData['amount_gross'], number_format($total,2) ) )
                {
                    $pfError = true;
                    $pfErrMsg = PF_ERR_AMOUNT_MISMATCH;
                }
            }
            $log[] = 'PAYFAST reported a transaction with the status of : '.$pfData['payment_status'];
            //// Check status and update order
            if( !$pfError && !$pfDone )
            {
                pflog( 'Check status and update order' );    
        
                switch( $pfData['payment_status'] )
                {
                    case 'COMPLETE':
                        pflog( '- Complete' );

                        foreach( $pfData as $key=>$value )
                        {
                            $params[] = $db->escape($key.'='.$value);               
                        }
                        $params = is_array($params) ? implode("\n",$params) : '';
                         //set the subscription state to Accepted
                        $query->clear()
                            ->update($db->qn('#__rseventspro_users'))
                            ->set($db->qn('state').' = 1')
                            ->set($db->qn('params').' = '.$db->q($params))
                            ->where($db->qn('id').' = '.(int) $subscriber);
                        
                        $db->setQuery($query);
                        $db->execute();
                        
                        $log[] = "Successfully added the payment to the database.";
                        
                        //send the activation email
                        require_once JPATH_SITE.'/components/com_rseventspro/helpers/emails.php';
                        
                        rseventsproHelper::confirm($subscriber);                        
                        break;
        
                    case 'FAILED':
                        pflog( '- Failed' );
                        $log[] = "Could not verify transaction authencity. PayFast said it's invalid.";
                        break;
        
                    case 'PENDING':
                        pflog( '- Pending' );
        
                        // Need to wait for "Completed" before processing
                        break;
        
                    default:
                        // If unknown status, do nothing (safest course of action)
                    break;
                }
            }

        }    
    
        // If an error occurred
        if( $pfError )
        {
            pflog( 'Error occurred: '. $pfErrMsg );
            $log[] = "Could not verify transaction authencity. Error occurred: ". $pfErrMsg;
            
        }
        rseventsproHelper::savelog($log,$subscriber);  
        exit();
    }
    
    public function rsepro_tax($vars) {
        if (!$this->canRun()) 
            return;
        
        if (isset($vars['method']) && $vars['method'] == $this->rsprooption) {
            $total      = isset($vars['total']) ? $vars['total'] : 0;
            $tax_value  = $this->params->get('tax_value',0);
            $tax_type   = $this->params->get('tax_type',0);
            
            return rseventsproHelper::setTax($total,$tax_type,$tax_value);
        }
    }
    
    public function rsepro_info($vars) {
        if (!$this->canRun()) 
            return;
        
        if (isset($vars['method']) && $vars['method'] == $this->rsprooption) {
            $app = JFactory::getApplication();
            
            $params = array();
            $data   = $vars['data'];
            
            if (!empty($data)) {
                if (!is_array($data)) {
                    $data   = explode("\n",$data);
                    if (!empty($data)) {
                        foreach ($data as $line) {
                            $linearray = explode('=',$line);
                            
                            if (!empty($linearray))
                                $params[trim($linearray[0])] = trim($linearray[1]);
                        }
                    }
                } else {
                    $params = $data;
                }
                
                echo $app->isAdmin() ? '<fieldset>' : '<fieldset class="rs_fieldset">';
                echo '<legend>'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_PAYMENT_DETAILS').'</legend>';
                echo '<table width="100%" border="0" class="table table-striped adminform rs_table">';
                echo '<tr>';
                echo '<td width="25%" align="right"><b>'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_TRANSACTION_ID').'</b></td>';
                echo '<td>'.$params['pf_payment_id'].'</td>';
                echo '</tr>';
                echo '<tr>';
                echo '<td width="25%" align="right"><b>'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_PAYER_NAME').'</b></td>';
                echo '<td>'.$params['name_first'].' '.$params['name_last'].'</td>';
                echo '</tr>';
                echo '<tr>';
                echo '<td width="25%" align="right"><b>'.JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_PAYER_EMAIL').'</b></td>';
                echo '<td>'.$params['email_address'].'</td>';
                echo '</tr>';
                echo '</table>';
                echo '</fieldset>';
            }
        }
    }
    
    public function rsepro_name($vars) {
        if (!$this->canRun()) 
            return;
        
        if (isset($vars['gateway']) && $vars['gateway'] == $this->rsprooption) {
            return JText::_('COM_RSEVENTSPRO_PLG_PLUGIN_PAYFAST_NAME');
        }
    }
    
    protected function escape($string) {
        return htmlentities($string, ENT_COMPAT, 'UTF-8');
    }
    
    protected function convertprice($price) {
        return number_format($price, 2, '.', '');
    }
}