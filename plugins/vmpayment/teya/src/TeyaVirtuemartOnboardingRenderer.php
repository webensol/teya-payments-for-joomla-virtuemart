<?php
/**
 * @package    Teya Payments for VirtueMart
 * @license    GNU General Public License version 3; see LICENSE.txt
 */
defined('_JEXEC') or die('Restricted access');

require_once __DIR__ . '/TeyaVirtuemartLanguage.php';

final class TeyaVirtuemartOnboardingRenderer
{
    private $method;

    public function render($method, callable $registrationUrlBuilder, ?callable $disconnectUrlBuilder = null, ?callable $statusUrlBuilder = null)
    {
        $this->method = $method;
        $id = (int) @$method->virtuemart_paymentmethod_id;
        $activeEnvironment = TeyaVirtuemartUtils::environmentFromParams($method);
        $assetUrl = rtrim(JUri::root(true), '/') . '/plugins/vmpayment/teya/assets/';
        $liveConnected = TeyaVirtuemartCredentials::isConnected($method, TeyaVirtuemartUtils::ENV_LIVE);
        $testConnected = TeyaVirtuemartCredentials::isConnected($method, TeyaVirtuemartUtils::ENV_TEST);

        $messages = TeyaVirtuemartLanguage::messages(array(
            'REQUEST_FAILED', 'ENVIRONMENT_MISMATCH', 'WAITING_CONNECTION', 'OPEN_TEYA',
            'CONNECTION_SAVED', 'CONNECTION_EXPIRED', 'ENV_TEST', 'ENV_LIVE',
        ));
        $content = '<div class="teya-onboarding-container" data-messages="' . $messages . '" data-active-mode="' . htmlspecialchars($activeEnvironment, ENT_QUOTES, 'UTF-8') . '">';
        $content .= '<div class="teya-onboarding-header">';
        $content .= '<div class="teya-onboarding-header-left"><img class="teya-logo" src="' . $assetUrl . 'teya.png" alt="Teya"><h2>' . vmText::_('VMPAYMENT_TEYA_ALL_IN_ONE') . '</h2></div>';
        $content .= '<div class="teya-onboarding-header-right"><div class="teya-onboarding-header-cards"><img src="' . $assetUrl . 'visa-dark.svg" alt="Visa"><img src="' . $assetUrl . 'mastercard-dark.svg" alt="Mastercard"><img src="' . $assetUrl . 'amex.svg" alt="American Express"></div></div>';
        $content .= '</div>';
        $content .= '<div class="teya-connect-section"><div class="teya-connect-content">';
        $content .= '<h3>' . vmText::_('VMPAYMENT_TEYA_CONNECT_ACCOUNT') . '</h3>';
        $content .= '<p class="description">' . vmText::_('VMPAYMENT_TEYA_CONNECT_COPY') . '</p>';

        if (!$id) {
            $content .= '<p class="teya-muted">' . vmText::_('VMPAYMENT_TEYA_SAVE_FIRST') . '</p>';
        } else {
            $content .= $this->renderConnectButton($id, TeyaVirtuemartUtils::ENV_LIVE, $liveConnected, $assetUrl, $registrationUrlBuilder, $disconnectUrlBuilder, $statusUrlBuilder);
            $content .= $this->renderConnectButton($id, TeyaVirtuemartUtils::ENV_TEST, $testConnected, $assetUrl, $registrationUrlBuilder, $disconnectUrlBuilder, $statusUrlBuilder);
        }

        $content .= '<div class="teya-connect-info">';
        $content .= '<p>' . vmText::_('VMPAYMENT_TEYA_TERMS_PREFIX') . ' <a href="https://teya.com/terms" target="_blank" rel="noopener">' . vmText::_('VMPAYMENT_TEYA_TERMS') . '</a> ' . vmText::_('VMPAYMENT_TEYA_AND') . ' <a href="https://teya.com/privacy" target="_blank" rel="noopener">' . vmText::_('VMPAYMENT_TEYA_PRIVACY') . '</a>.</p>';
        $content .= '</div></div></div>';
        $content .= '<div class="teya-help-section"><h3>' . vmText::_('VMPAYMENT_TEYA_NEED_HELP') . '</h3><p>' . vmText::_('VMPAYMENT_TEYA_VISIT_DOCS') . ' <a href="https://developer.teya.com/" target="_blank" rel="noopener">' . vmText::_('VMPAYMENT_TEYA_DOCUMENTATION') . '</a> ' . vmText::_('VMPAYMENT_TEYA_OR') . ' <a href="https://support.teya.com/" target="_blank" rel="noopener">' . vmText::_('VMPAYMENT_TEYA_CONTACT_SUPPORT') . '</a>.</p></div>';
        $content .= '</div>';

        return $content;
    }

    private function renderConnectButton($id, $environment, $connected, $assetUrl, callable $registrationUrlBuilder, ?callable $disconnectUrlBuilder = null, ?callable $statusUrlBuilder = null)
    {
        $connectLabel = $environment === TeyaVirtuemartUtils::ENV_TEST ? vmText::_('VMPAYMENT_TEYA_CONNECT_TEST') : vmText::_('VMPAYMENT_TEYA_CONNECT_LIVE');
        $reconnectLabel = $environment === TeyaVirtuemartUtils::ENV_TEST ? vmText::_('VMPAYMENT_TEYA_RECONNECT_TEST') : vmText::_('VMPAYMENT_TEYA_RECONNECT_LIVE');
        $label = $connected
            ? ($environment === TeyaVirtuemartUtils::ENV_TEST ? vmText::_('VMPAYMENT_TEYA_RECONNECT_TEST') : vmText::_('VMPAYMENT_TEYA_RECONNECT_LIVE'))
            : ($environment === TeyaVirtuemartUtils::ENV_TEST ? vmText::_('VMPAYMENT_TEYA_CONNECT_TEST') : vmText::_('VMPAYMENT_TEYA_CONNECT_LIVE'));
        $statusClass = $connected ? 'is-connected' : 'is-disconnected';
        $statusText = $connected ? vmText::_('VMPAYMENT_TEYA_CONNECTED') : vmText::_('VMPAYMENT_TEYA_NOT_CONNECTED_SHORT');
        $url = call_user_func($registrationUrlBuilder, $id, $environment);
        $statusUrl = $statusUrlBuilder ? call_user_func($statusUrlBuilder, $id, $environment) : '';

        $disconnect = ($disconnectUrlBuilder) ? '<a class="teya-disconnect-button" ' . ($connected ? '' : 'hidden ') . 'href="' . htmlspecialchars(call_user_func($disconnectUrlBuilder, $id, $environment), ENT_QUOTES, 'UTF-8') . '" data-teya-status-url="' . htmlspecialchars($statusUrl, ENT_QUOTES, 'UTF-8') . '">' . vmText::_('VMPAYMENT_TEYA_DISCONNECT') . '</a>' : '';
        $poll = $statusUrl !== '' ? '<p class="teya-poll-status" aria-live="polite"></p>' : '';
        return '<div class="teya-connect-option" data-teya-mode="' . $environment . '" data-connected-label="' . htmlspecialchars(vmText::_('VMPAYMENT_TEYA_CONNECTED'), ENT_QUOTES, 'UTF-8') . '" data-disconnected-label="' . htmlspecialchars(vmText::_('VMPAYMENT_TEYA_NOT_CONNECTED_SHORT'), ENT_QUOTES, 'UTF-8') . '" data-connect-label="' . htmlspecialchars($connectLabel, ENT_QUOTES, 'UTF-8') . '" data-reconnect-label="' . htmlspecialchars($reconnectLabel, ENT_QUOTES, 'UTF-8') . '"><div class="teya-connect-button-container"><p class="teya-environment-status ' . $statusClass . '">' . $statusText . '</p><a class="teya-connect-button" target="_blank" rel="noopener" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" data-teya-status-url="' . htmlspecialchars($statusUrl, ENT_QUOTES, 'UTF-8') . '" data-teya-environment="' . htmlspecialchars($environment, ENT_QUOTES, 'UTF-8') . '"><img class="teya-connect-logo" src="' . $assetUrl . 'teya.png" alt="Teya"><span class="teya-connect-text">' . $label . '</span></a>' . $disconnect . $poll . '</div></div>';
    }
}
