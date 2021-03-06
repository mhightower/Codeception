<?php
namespace Codeception\Lib;

use Codeception\Configuration;
use Codeception\Exception\ElementNotFound;
use Codeception\Exception\ExternalUrlException;
use Codeception\Exception\MalformedLocatorException;
use Codeception\Exception\ModuleException;
use Codeception\Exception\TestRuntimeException;
use Codeception\Lib\Interfaces\ElementLocator;
use Codeception\Lib\Interfaces\PageSourceSaver;
use Codeception\Lib\Interfaces\SupportsDomainRouting;
use Codeception\Lib\Interfaces\Web;
use Codeception\Module;
use Codeception\PHPUnit\Constraint\Crawler as CrawlerConstraint;
use Codeception\PHPUnit\Constraint\CrawlerNot as CrawlerNotConstraint;
use Codeception\PHPUnit\Constraint\Page as PageConstraint;
use Codeception\TestCase;
use Codeception\Util\Locator;
use Codeception\Util\PropertyAccess;
use Codeception\Util\Uri;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Link;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Field\InputFormField;
use Symfony\Component\DomCrawler\Field\TextareaFormField;
use Symfony\Component\DomCrawler\Form;

class InnerBrowser extends Module implements Web, PageSourceSaver, ElementLocator
{
    /**
     * @var \Symfony\Component\DomCrawler\Crawler
     */
    protected $crawler;

    /**
     * @api
     * @var \Symfony\Component\BrowserKit\Client
     */
    public $client;

    /**
     * @var array|\Symfony\Component\DomCrawler\Form[]
     */
    protected $forms = [];

    protected $defaultCookieParameters = ['expires' => null, 'path' => '/', 'domain' => '', 'secure' => false];

    public function _failed(TestCase $test, $fail)
    {
        if (!$this->client || !$this->client->getInternalResponse()) {
            return;
        }
        $this->_savePageSource(codecept_output_dir().str_replace(['::', '\\', '/'], ['.', '.', '.'], TestCase::getTestSignature($test)) . '.fail.html');
    }

    public function _after(TestCase $test)
    {
        $this->client = null;
        $this->crawler = null;
        $this->forms = [];
    }

    public function _conflicts()
    {
        return 'Codeception\Lib\Interfaces\Web';
    }

    public function _findElements($locator)
    {
        return $this->match($locator);
    }

    /**
     * Send custom request to a backend using method, uri, parameters, etc.
     * Use it in Helpers to create special request actions, like accessing API
     * Returns a string with response body.
     *
     * ```php
     * <?php
     * // in Helper class
     * public function createUserByApi($name) {
     *     $userData = $this->getModule('{{MODULE_NAME}}')->_request('POST', '/api/v1/users', ['name' => $name]);
     *     $user = json_decode($userData);
     *     return $user->id;
     * }
     * ?>
     * ```
     * Does not load the response into the module so you can't interact with response page (click, fill forms).
     * To load arbitrary page for interaction, use `_loadPage` method.
     *
     * @api
     * @param $method
     * @param $uri
     * @param array $parameters
     * @param array $files
     * @param array $server
     * @param null $content
     * @return mixed|Crawler
     * @throws ExternalUrlException
     * @see `_loadPage`
     */
    public function _request($method, $uri, array $parameters = [],  array $files = [], array $server = [], $content = null)
    {
        $this->clientRequest($method, $uri, $parameters, $files, $server, $content, false);
        return $this->getRunningClient()->getInternalResponse()->getContent();
    }

    protected function clientRequest($method, $uri, array $parameters = [],  array $files = [], array $server = [], $content = null, $changeHistory = true)
    {
        if ($this instanceof Framework) {
            if ($method !== 'GET' && $content === null && !empty($parameters)) {
                $content = http_build_query($parameters);
            }

            if (preg_match('#^(//|https?://(?!localhost))#', $uri) && (!$this instanceof SupportsDomainRouting)) {
                throw new ExternalUrlException(get_class($this) . " can't open external URL: " . $uri);
            }
        }

        if (!PropertyAccess::readPrivateProperty($this->client, 'followRedirects')) {
            $result = $this->client->request($method, $uri, $parameters, $files, $server, $content, $changeHistory);
            $this->debugResponse($uri);
            return $result;
        } else {
            $maxRedirects = PropertyAccess::readPrivateProperty($this->client, 'maxRedirects', 'Symfony\Component\BrowserKit\Client');
            $this->client->followRedirects(false);
            $result = $this->client->request($method, $uri, $parameters, $files, $server, $content, $changeHistory);
            $this->debugResponse($uri);
            return $this->redirectIfNecessary($result, $maxRedirects, 0);
        }
    }

    /**
     * Opens a page with arbitrary request parameters.
     * Useful for testing multi-step forms on a specific step.
     *
     * ```php
     * <?php
     * // in Helper class
     * public function openCheckoutFormStep2($orderId) {
     *     $this->getModule('{{MODULE_NAME}}')->_loadPage('POST', '/checkout/step2', ['order' => $orderId]);
     * }
     * ?>
     * ```
     *
     * @api
     * @param $method
     * @param $uri
     * @param array $parameters
     * @param array $files
     * @param array $server
     * @param null $content
     */
    public function _loadPage($method, $uri, array $parameters = [],  array $files = [], array $server = [], $content = null)
    {
        $this->crawler = $this->clientRequest($method, $uri, $parameters, $files, $server, $content);
        $this->forms = [];
    }

    /**
     * @return Crawler
     * @throws ModuleException
     */
    private function getCrawler()
    {
        if (!$this->crawler) {
            throw new ModuleException($this, 'Crawler is null. Perhaps you forgot to call "amOnPage"?');
        }
        return $this->crawler;
    }

    private function getRunningClient()
    {
        if ($this->client->getHistory()->isEmpty()) {
            throw new ModuleException($this, "Page not loaded. Use `\$I->amOnPage` (or hidden API methods `_request` and `_loadPage`) to open it");
        }
        return $this->client;
    }

    public function _savePageSource($filename)
    {
        file_put_contents($filename, $this->getRunningClient()->getInternalResponse()->getContent());
    }

    /**
     * Authenticates user for HTTP_AUTH
     *
     * @param $username
     * @param $password
     */
    public function amHttpAuthenticated($username, $password)
    {
        $this->client->setServerParameter('PHP_AUTH_USER', $username);
        $this->client->setServerParameter('PHP_AUTH_PW', $password);
    }


    public function amOnPage($page)
    {
        $this->_loadPage('GET', $page);
    }

    public function click($link, $context = null)
    {
        if ($context) {
            $this->crawler = $this->match($context);
        }

        if (is_array($link)) {
            $this->clickByLocator($link);
            return;
        }
        $anchor = $this->strictMatch(['link' => $link]);
        if (!count($anchor)) {
            $anchor = $this->getCrawler()->selectLink($link);
        }
        if (count($anchor)) {
            $this->crawler = $this->clientClick($anchor->first()->link());
            $this->forms = [];
            return;
        }

        $buttonText = str_replace('"', "'", $link);
        $button = $this->crawler->selectButton($buttonText);
        if (count($button)) {
            $buttonValue = [];
            if (strval($button->attr('name')) !== '' && $button->attr('value') !== null) {
                $buttonValue = [$button->attr('name') => $button->attr('value')];
            }
            $this->proceedSubmitForm(
                $button->parents()->filter('form')->first(),
                $buttonValue
            );
            return;
        }

        try {
            $this->clickByLocator($link);
        } catch (MalformedLocatorException $e) {
            throw new ElementNotFound("name=$link", "'$link' is invalid CSS and XPath selector and Link or Button");
        }
    }

    protected function clickByLocator($link)
    {
        $nodes = $this->match($link);
        if (!$nodes->count()) {
            throw new ElementNotFound($link, 'Link or Button by name or CSS or XPath');
        }

        foreach ($nodes as $node) {
            $tag = $node->nodeName;
            $type = $node->getAttribute('type');
            if ($tag === 'a') {
                $this->crawler = $this->clientClick($nodes->first()->link());
                $this->forms = [];
                break;
            } elseif (in_array($tag, ['input', 'button']) && in_array($type, ['submit', 'image'])) {
                $buttonValue = [];
                if (strval($nodes->first()->attr('name')) !== '' && $nodes->first()->attr('value') !== null) {
                    $buttonValue = [$nodes->first()->attr('name') => $nodes->first()->attr('value')];
                }
                $this->proceedSubmitForm(
                    $nodes->parents()->filter('form')->first(),
                    $buttonValue
                );
                break;
            }
        }
    }

    public function see($text, $selector = null)
    {
        if (!$selector) {
            $this->assertPageContains($text);
        } else {
            $nodes = $this->match($selector);
            $this->assertDomContains($nodes, $this->stringifySelector($selector), $text);
        }
    }

    public function dontSee($text, $selector = null)
    {
        if (!$selector) {
            $this->assertPageNotContains($text);
        } else {
            $nodes = $this->match($selector);
            $this->assertDomNotContains($nodes, $this->stringifySelector($selector), $text);
        }
    }

    public function seeLink($text, $url = null)
    {
        $links = $this->getCrawler()->selectLink($text);
        if ($url) {
            $links = $links->filterXPath(sprintf('.//a[contains(@href, %s)]', Crawler::xpathLiteral($url)));
        }
        $this->assertDomContains($links, 'a');
    }

    public function dontSeeLink($text, $url = null)
    {
        $links = $this->getCrawler()->selectLink($text);
        if ($url) {
            $links = $links->filterXPath(sprintf('.//a[contains(@href, %s)]', Crawler::xpathLiteral($url)));
        }
        $this->assertDomNotContains($links, 'a');
    }

    /**
     * @return string
     * @throws ModuleException
     */
    public function _getCurrentUri()
    {
        return Uri::retrieveUri($this->getRunningClient()->getHistory()->current()->getUri());
    }

    public function seeInCurrentUrl($uri)
    {
        $this->assertContains($uri, $this->_getCurrentUri());
    }

    public function dontSeeInCurrentUrl($uri)
    {
        $this->assertNotContains($uri, $this->_getCurrentUri());
    }

    public function seeCurrentUrlEquals($uri)
    {
        $this->assertEquals(rtrim($uri, '/'), rtrim($this->_getCurrentUri(), '/'));
    }

    public function dontSeeCurrentUrlEquals($uri)
    {
        $this->assertNotEquals(rtrim($uri, '/'), rtrim($this->_getCurrentUri(), '/'));
    }

    public function seeCurrentUrlMatches($uri)
    {
        \PHPUnit_Framework_Assert::assertRegExp($uri, $this->_getCurrentUri());
    }

    public function dontSeeCurrentUrlMatches($uri)
    {
        \PHPUnit_Framework_Assert::assertNotRegExp($uri, $this->_getCurrentUri());
    }

    public function grabFromCurrentUrl($uri = null)
    {
        if (!$uri) {
            return $this->_getCurrentUri();
        }
        $matches = [];
        $res     = preg_match($uri, $this->_getCurrentUri(), $matches);
        if (!$res) {
            $this->fail("Couldn't match $uri in " . $this->_getCurrentUri());
        }
        if (!isset($matches[1])) {
            $this->fail("Nothing to grab. A regex parameter required. Ex: '/user/(\\d+)'");
        }
        return $matches[1];
    }

    public function seeCheckboxIsChecked($checkbox)
    {
        $checkboxes = $this->getCrawler()->filter($checkbox);
        $this->assertDomContains($checkboxes->filter('input[checked=checked]'), 'checkbox');
    }

    public function dontSeeCheckboxIsChecked($checkbox)
    {
        $checkboxes = $this->getCrawler()->filter($checkbox);
        $this->assertEquals(0, $checkboxes->filter('input[checked=checked]')->count());
    }

    public function seeInField($field, $value)
    {
        $nodes = $this->getFieldsByLabelOrCss($field);
        $this->assert($this->proceedSeeInField($nodes, $value));
    }

    public function dontSeeInField($field, $value)
    {
        $nodes = $this->getFieldsByLabelOrCss($field);
        $this->assertNot($this->proceedSeeInField($nodes, $value));
    }

    public function seeInFormFields($formSelector, array $params)
    {
        $this->proceedSeeInFormFields($formSelector, $params, false);
    }

    public function dontSeeInFormFields($formSelector, array $params)
    {
        $this->proceedSeeInFormFields($formSelector, $params, true);
    }

    protected function proceedSeeInFormFields($formSelector, array $params, $assertNot)
    {
        $form = $this->match($formSelector)->first();
        if ($form->count() === 0) {
            throw new ElementNotFound($formSelector, 'Form');
        }
        foreach ($params as $name => $values) {
            $field = $form->filterXPath(sprintf('.//*[@name=%s]', Crawler::xpathLiteral($name)));
            if ($field->count() === 0) {
                throw new ElementNotFound(
                    sprintf('//*[@name=%s]', Crawler::xpathLiteral($name)),
                    'Form'
                );
            }
            if (!is_array($values)) {
                $values = [$values];
            }
            foreach ($values as $value) {
                $ret = $this->proceedSeeInField($field, $value);
                if ($assertNot) {
                    $this->assertNot($ret);
                } else {
                    $this->assert($ret);
                }
            }
        }
    }

    protected function proceedSeeInField(Crawler $fields, $value)
    {
        $testValues = $this->proceedGetValueFromField($fields);
        if (!is_array($testValues)) {
            $testValues = [$testValues];
        }
        if (is_bool($value) && $value === true && !empty($testValues)) {
            $value = reset($testValues);
        } elseif (empty($testValues)) {
            $testValues = [''];
        }
        return [
            'Contains',
            $value,
            $testValues,
            sprintf('Failed asserting that `%s` is in %s\'s value: %s', $value, $fields->nodeName(), var_export($testValues, true))
        ];
    }

    /**
     * Strips out one pair of trailing square brackets from a field's
     * name.
     *
     * @param string $name the field name
     * @return string the name after stripping trailing square brackets
     */
    protected function getSubmissionFormFieldName($name)
    {
        if (substr($name, -2) === '[]') {
            return substr($name, 0, -2);
        }
        return $name;
    }

    /**
     * Replaces boolean values in $params with the corresponding field's
     * value for checkbox form fields.
     *
     * The function loops over all input checkbox fields, checking if a
     * corresponding key is set in $params.  If it is, and the value is
     * boolean or an array containing booleans, the value(s) are
     * replaced in the array with the real value of the checkbox, and
     * the array is returned.
     *
     * @param Crawler $form the form to find checkbox elements
     * @param array $params the parameters to be submitted
     * @return array the $params array after replacing bool values
     */
    protected function setCheckboxBoolValues(Crawler $form, array $params)
    {
        $checkboxes = $form->filter('input[type=checkbox]');
        $chFoundByName = [];
        foreach ($checkboxes as $box) {
            $fieldName = $this->getSubmissionFormFieldName($box->getAttribute('name'));
            $pos = (!isset($chFoundByName[$fieldName])) ? 0 : $chFoundByName[$fieldName];
            $skip = (!isset($params[$fieldName]))
                || (!is_array($params[$fieldName]) && !is_bool($params[$fieldName]))
                || ($pos >= count($params[$fieldName])
                || (is_array($params[$fieldName]) && !is_bool($params[$fieldName][$pos])));
            if ($skip) {
                continue;
            }
            $values = $params[$fieldName];
            if ($values === true) {
                $params[$fieldName] = $box->getAttribute('value');
                $chFoundByName[$fieldName] = $pos + 1;
            } elseif ($values[$pos] === true) {
                $params[$fieldName][$pos] = $box->getAttribute('value');
                $chFoundByName[$fieldName] = $pos + 1;
            } elseif (is_array($values)) {
                array_splice($params[$fieldName], $pos, 1);
            } else {
                unset($params[$fieldName]);
            }
        }
        return $params;
    }

    /**
     * Submits the form currently selected in the passed Crawler, after
     * setting any values passed in $params and setting the value of the
     * passed button name.
     *
     * @param Crawler $frmCrawl the form to submit
     * @param array $params additional parameter values to set on the
     *        form
     * @param string $button the name of a submit button in the form
     */
    protected function proceedSubmitForm(Crawler $frmCrawl, array $params, $button = null)
    {
        $form = $this->getFormFor($frmCrawl);
        $defaults = $this->getFormValuesFor($form);
        $merged = array_merge($defaults, $params);
        $requestParams = $this->setCheckboxBoolValues($frmCrawl, $merged);

        if (!empty($button)) {
            $btnCrawl = $frmCrawl->filterXPath(sprintf('//*[not(@disabled) and @type="submit" and @name=%s]', Crawler::xpathLiteral($button)));
            if (count($btnCrawl)) {
                $requestParams[$button] = $btnCrawl->attr('value');
            }
        }

        $url = $this->getFormUrl($frmCrawl);
        if (strcasecmp($form->getMethod(), 'GET') === 0) {
            $url = Uri::mergeUrls($url, '?' . http_build_query($requestParams));
        }
        $this->debugSection('Uri', $url);
        $this->debugSection('Method', $form->getMethod());
        $this->debugSection('Parameters', $requestParams);

        $requestParams= $this->getFormPhpValues($requestParams);

        $this->crawler = $this->clientRequest(
            $form->getMethod(),
            $url,
            $requestParams,
            $form->getPhpFiles()
        );
        $this->forms = [];
    }

    public function submitForm($selector, array $params, $button = null)
    {
        $form = $this->match($selector)->first();
        if (!count($form)) {
            throw new ElementNotFound($this->stringifySelector($selector), 'Form');
        }
        $this->proceedSubmitForm($form, $params, $button);
    }

    /**
     * Returns an absolute URL for the passed URI with the current URL
     * as the base path.
     *
     * @param string $uri the absolute or relative URI
     * @return string the absolute URL
     * @throws \Codeception\Exception\TestRuntimeException if either the current
     *         URL or the passed URI can't be parsed
     */
    protected function getAbsoluteUrlFor($uri)
    {
        $currentUrl = $this->getRunningClient()->getHistory()->current()->getUri();
        if (empty($uri) || $uri === '#') {
            return $currentUrl;
        }
        return Uri::mergeUrls($currentUrl, $uri);
    }

    /**
     * Returns the form action's absolute URL.
     *
     * @param \Symfony\Component\DomCrawler\Crawler $form
     * @return string
     * @throws \Codeception\Exception\TestRuntimeException if either the current
     *         URL or the URI of the form's action can't be parsed
     */
    protected function getFormUrl(Crawler $form)
    {
        $action = $form->attr('action');
        return $this->getAbsoluteUrlFor($action);
    }

    /**
     * Returns a crawler Form object for the form pointed to by the
     * passed Crawler.
     *
     * The returned form is an independent Crawler created to take care
     * of the following issues currently experienced by Crawler's form
     * object:
     *  - input fields disabled at a higher level (e.g. by a surrounding
     *    fieldset) still return values
     *  - Codeception expects an empty value to match an unselected
     *    select box.
     *
     * The function clones the crawler's node and creates a new crawler
     * because it destroys or adds to the DOM for the form to achieve
     * the desired functionality.  Other functions simply querying the
     * DOM wouldn't expect them.
     *
     * @param Crawler $form the form
     * @param string $action the form's absolute URL action
     * @return Form
     */
    private function getFormFromCrawler(Crawler $form, $action)
    {
        $fakeDom = new \DOMDocument();
        $fakeDom->appendChild($fakeDom->importNode($form->getNode(0), true));
        $node = $fakeDom->documentElement;
        $cloned = new Crawler($node, $action);
        $shouldDisable = $cloned->filter('input:disabled:not([disabled]),select option:disabled,select optgroup:disabled option:not([disabled])');
        foreach ($shouldDisable as $field) {
            $field->parentNode->removeChild($field);
        }
        $selectNonMulti = $cloned->filterXPath('//select[not(@multiple) and not(option[@value=""])]');
        $opt = new \DOMElement('option');
        foreach ($selectNonMulti as $field) {
            $node = $field->insertBefore($opt, $field->firstChild);
            $node->setAttribute('value', '');
        }
        return $cloned->form();
    }

    /**
     * Returns the DomCrawler\Form object for the form pointed to by
     * $node or its closes form parent.
     *
     * @param \Symfony\Component\DomCrawler\Crawler $node
     * @return \Symfony\Component\DomCrawler\Form
     */
    protected function getFormFor(Crawler $node)
    {
        if (strcasecmp($node->first()->getNode(0)->tagName, 'form') === 0) {
            $form = $node->first();
        } else {
            $form = $node->parents()->filter('form')->first();
        }
        if (!$form) {
            $this->fail('The selected node is not a form and does not have a form ancestor.');
        }
        $action = (string) $this->getFormUrl($form);
        if (!isset($this->forms[$action])) {
            $this->forms[$action] = $this->getFormFromCrawler($form, $action);
        }
        return $this->forms[$action];
    }

    /**
     * Returns an array of name => value pairs for the passed form.
     *
     * For form fields containing a name ending in [], an array is
     * created out of all field values with the given name.
     *
     * @param \Symfony\Component\DomCrawler\Form the form
     * @return array an array of name => value pairs
     */
    protected function getFormValuesFor(Form $form)
    {
        $values = [];
        $fields = $form->all();
        foreach ($fields as $field) {
            if ($field->isDisabled() || !$field->hasValue() || $field instanceof FileFormField) {
                continue;
            }
            $fieldName = $this->getSubmissionFormFieldName($field->getName());
            if (substr($field->getName(), -2) === '[]') {
                if (!isset($values[$fieldName])) {
                    $values[$fieldName] = [];
                }
                $values[$fieldName][] = $field->getValue();
            } else {
                $values[$fieldName] = $field->getValue();
            }
        }
        return $values;
    }

    public function fillField($field, $value)
    {
        $input = $this->getFieldByLabelOrCss($field);
        $form = $this->getFormFor($input);
        $name = $input->attr('name');

        $dynamicField = $input->getNode(0)->tagName == 'textarea'
            ? new TextareaFormField($input->getNode(0))
            : new InputFormField($input->getNode(0));
        $formField = $this->matchFormField($name, $form, $dynamicField);
        $formField->setValue($value);
        $input->getNode(0)->nodeValue = htmlspecialchars($value);
    }

    /**
     * @param $field
     *
     * @return \Symfony\Component\DomCrawler\Crawler
     */
    protected function getFieldsByLabelOrCss($field)
    {
        if (is_array($field)) {
            $input = $this->strictMatch($field);
            if (!count($input)) {
                throw new ElementNotFound($field);
            }
            return $input;
        }

        // by label
        $label = $this->strictMatch(['xpath' => sprintf('.//label[text()=%s]', Crawler::xpathLiteral($field))]);
        if (count($label)) {
            $label = $label->first();
            if ($label->attr('for')) {
                $input = $this->strictMatch(['id' => $label->attr('for')]);
            }
        }

        // by name
        if (!isset($input)) {
            $input = $this->strictMatch(['name' => $field]);
        }

        // by CSS and XPath
        if (!count($input)) {
            $input = $this->match($field);
        }

        if (!count($input)) {
            throw new ElementNotFound($field, 'Form field by Label or CSS');
        }

        return $input;
    }

    protected function getFieldByLabelOrCss($field)
    {
        $input = $this->getFieldsByLabelOrCss($field);
        return $input->first();
    }

    public function selectOption($select, $option)
    {
        $field = $this->getFieldByLabelOrCss($select);
        $form = $this->getFormFor($field);
        $fieldName = $this->getSubmissionFormFieldName($field->attr('name'));

        if (is_array($option)) {
            $options = [];
            foreach ($option as $opt) {
                $options[] = $this->matchOption($field, $opt);
            }
            $form[$fieldName]->select($options);
            return;
        }

        $dynamicField = new ChoiceFormField($field->getNode(0));
        $formField = $this->matchFormField($fieldName, $form, $dynamicField);
        $selValue = $this->matchOption($field, $option);

        if (is_array($formField)) {
            foreach ($formField as $field) {
                $values = $field->availableOptionValues();
                foreach ($values as $val) {
                    if ($val === $option) {
                        $field->select($selValue);
                        return;
                    }
                }
            }
            return;
        }

        $formField->select($this->matchOption($field, $option));
    }

    protected function matchOption(Crawler $field, $option)
    {
        $options = $field->filterXPath(sprintf('//option[text()=normalize-space("%s")]|//input[@type="radio" and @value=normalize-space("%s")]', $option, $option));
        if ($options->count()) {
            if ($options->getNode(0)->tagName === 'option') {
                $options->getNode(0)->setAttribute('selected', 'selected');
            } else {
                $options->getNode(0)->setAttribute('checked', 'checked');
            }
            if ($options->first()->attr('value') !== false) {
                return $options->first()->attr('value');
            }
            return $options->first()->text();
        }
        return $option;
    }

    public function checkOption($option)
    {
        $this->proceedCheckOption($option)->tick();
    }

    public function uncheckOption($option)
    {
        $this->proceedCheckOption($option)->untick();
    }

    /**
     * @param $option
     * @return ChoiceFormField
     */
    protected function proceedCheckOption($option)
    {
        $form = $this->getFormFor($field = $this->getFieldByLabelOrCss($option));
        $name = $field->attr('name');

        if ($field->getNode(0) === null) {
            throw new TestRuntimeException("Form field $name is not located");
        }
        // If the name is an array than we compare objects to find right checkbox
        $formField = $this->matchFormField($name, $form, new ChoiceFormField($field->getNode(0)));
        $field->getNode(0)->setAttribute('checked', 'checked');
        if (!$formField instanceof ChoiceFormField) {
            throw new TestRuntimeException("Form field $name is not a checkable");
        }
        return $formField;
    }

    public function attachFile($field, $filename)
    {
        $form = $this->getFormFor($field = $this->getFieldByLabelOrCss($field));
        $path = Configuration::dataDir() . $filename;
        $name = $field->attr('name');
        if (!is_readable($path)) {
            $this->fail("file $filename not found in Codeception data path. Only files stored in data path accepted");
        }
        $formField = $this->matchFormField($name, $form, new FileFormField($field->getNode(0)));
        if (is_array($formField)) {
            $this->fail("Field $name is ignored on upload, field $name is treated as array.");
        }

        $formField->upload($path);
    }

    /**
     * If your page triggers an ajax request, you can perform it manually.
     * This action sends a GET ajax request with specified params.
     *
     * See ->sendAjaxPostRequest for examples.
     *
     * @param $uri
     * @param $params
     */
    public function sendAjaxGetRequest($uri, $params = [])
    {
        $this->sendAjaxRequest('GET', $uri, $params);
    }

    /**
     * If your page triggers an ajax request, you can perform it manually.
     * This action sends a POST ajax request with specified params.
     * Additional params can be passed as array.
     *
     * Example:
     *
     * Imagine that by clicking checkbox you trigger ajax request which updates user settings.
     * We emulate that click by running this ajax request manually.
     *
     * ``` php
     * <?php
     * $I->sendAjaxPostRequest('/updateSettings', array('notifications' => true)); // POST
     * $I->sendAjaxGetRequest('/updateSettings', array('notifications' => true)); // GET
     *
     * ```
     *
     * @param $uri
     * @param $params
     */
    public function sendAjaxPostRequest($uri, $params = [])
    {
        $this->sendAjaxRequest('POST', $uri, $params);
    }

    /**
     * If your page triggers an ajax request, you can perform it manually.
     * This action sends an ajax request with specified method and params.
     *
     * Example:
     *
     * You need to perform an ajax request specifying the HTTP method.
     *
     * ``` php
     * <?php
     * $I->sendAjaxRequest('PUT', '/posts/7', array('title' => 'new title'));
     *
     * ```
     *
     * @param $method
     * @param $uri
     * @param $params
     */
    public function sendAjaxRequest($method, $uri, $params = [])
    {
        $this->clientRequest($method, $uri, $params, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], null, false);
    }

    /**
     * @param $url
     */
    protected function debugResponse($url)
    {
        $this->debugSection('Page', $url);
        $this->debugSection('Response', $this->getResponseStatusCode());
        $this->debugSection('Cookies', $this->getRunningClient()->getInternalRequest()->getCookies());
        $this->debugSection('Headers', $this->getRunningClient()->getInternalResponse()->getHeaders());
    }

    protected function getResponseStatusCode()
    {
        // depending on Symfony version
        $response = $this->getRunningClient()->getInternalResponse();
        if (method_exists($response, 'getStatus')) {
            return $response->getStatus();
        }
        if (method_exists($response, 'getStatusCode')) {
            return $response->getStatusCode();
        }
        return "N/A";
    }

    /**
     * @param $selector
     *
     * @return Crawler
     */
    protected function match($selector)
    {
        if (is_array($selector)) {
            return $this->strictMatch($selector);
        }

        if (Locator::isCSS($selector)) {
            return $this->getCrawler()->filter($selector);
        }
        if (Locator::isXPath($selector)) {
            return $this->getCrawler()->filterXPath($selector);
        }
        throw new MalformedLocatorException($selector, 'XPath or CSS');
    }

    /**
     * @param array $by
     * @throws TestRuntimeException
     * @return Crawler
     */
    protected function strictMatch(array $by)
    {
        $type = key($by);
        $locator = $by[$type];
        switch ($type) {
            case 'id':
                return $this->filterByCSS("#$locator");
            case 'name':
                return $this->filterByXPath(sprintf('.//*[@name=%s]', Crawler::xpathLiteral($locator)));
            case 'css':
                return $this->filterByCSS($locator);
            case 'xpath':
                return $this->filterByXPath($locator);
            case 'link':
                return $this->filterByXPath(sprintf('.//a[.=%s]', Crawler::xpathLiteral($locator)));
            case 'class':
                return $this->filterByCSS(".$locator");
            default:
                throw new TestRuntimeException("Locator type '$by' is not defined. Use either: xpath, css, id, link, class, name");
        }
    }

    protected function filterByAttributes(Crawler $nodes, array $attributes)
    {
        foreach ($attributes as $attr => $val) {
            $nodes = $nodes->reduce(
                function (Crawler $node) use ($attr, $val) {
                    return $node->attr($attr) == $val;
                }
            );
        }
        return $nodes;

    }

    public function grabTextFrom($cssOrXPathOrRegex)
    {
        if (@preg_match($cssOrXPathOrRegex, $this->client->getInternalResponse()->getContent(), $matches)) {
            return $matches[1];
        }
        $nodes = $this->match($cssOrXPathOrRegex);
        if ($nodes->count()) {
            return $nodes->first()->text();
        }
        throw new ElementNotFound($cssOrXPathOrRegex, 'Element that matches CSS or XPath or Regex');
    }

    public function grabAttributeFrom($cssOrXpath, $attribute)
    {
        $nodes = $this->match($cssOrXpath);
        if (!$nodes->count()) {
            throw new ElementNotFound($cssOrXpath, 'Element that matches CSS or XPath');
        }
        return $nodes->first()->attr($attribute);
    }

    public function grabMultiple($cssOrXpath, $attribute = null)
    {
        $result = [];
        $nodes = $this->match($cssOrXpath);
        
        foreach ($nodes as $node) {
            if ($attribute !== null) {
                $result[] = $node->getAttribute($attribute);
            } else {
                $result[] = $node->textContent;
            }
        }
        return $result;
    }

    /**
     * @param $field
     *
     * @return array|mixed|null|string
     */
    public function grabValueFrom($field)
    {
        $nodes = $this->match($field);
        if (!$nodes->count()) {
            throw new ElementNotFound($field, 'Field');
        }
        return $this->proceedGetValueFromField($nodes);
    }

    /**
     * @param Crawler $nodes
     * @return array|mixed|string
     */
    protected function proceedGetValueFromField(Crawler $nodes)
    {
        $values = [];
        if ($nodes->filter('textarea')->count()) {
            return (new TextareaFormField($nodes->filter('textarea')->getNode(0)))->getValue();
        }

        if ($nodes->filter('input')->count()) {
            $input = $nodes->filter('input');
            if ($input->attr('type') == 'checkbox' or $input->attr('type') == 'radio') {
                $values = [];
                $input = $nodes->filter('input:checked');
                foreach ($input as $checkbox) {
                    $values[] = $checkbox->getAttribute('value');
                }
                return $values;
            }
            return (new InputFormField($nodes->filter('input')->getNode(0)))->getValue();
        }
        if ($nodes->filter('select')->count()) {
            $field = new ChoiceFormField($nodes->filter('select')->getNode(0));
            $options = $nodes->filter('option[selected]');
            foreach ($options as $option) {
                $values[] = $option->getAttribute('value');
            }
            if (!$field->isMultiple()) {
                return reset($values);
            }
            return $values;
        }

        $this->fail("Element $nodes is not a form field or does not contain a form field");
    }

    public function setCookie($name, $val, array $params = [])
    {
        $cookies = $this->client->getCookieJar();
        $params = array_merge($this->defaultCookieParameters, $params);

        $expires      = isset($params['expires']) ? $params['expires'] : null;
        $path         = isset($params['path'])    ? $params['path'] : null;
        $domain       = isset($params['domain'])  ? $params['domain'] : '';
        $secure       = isset($params['secure'])  ? $params['secure'] : false;
        $httpOnly     = isset($params['httpOnly'])  ? $params['httpOnly'] : true;
        $encodedValue = isset($params['encodedValue'])  ? $params['encodedValue'] : false;

        $cookies->set(new Cookie($name, $val, $expires, $path, $domain, $secure, $httpOnly, $encodedValue));
        $this->debugSection('Cookies', $this->client->getCookieJar()->all());
    }

    public function grabCookie($cookie, array $params = [])
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->debugSection('Cookies', $this->client->getCookieJar()->all());
        $cookies = $this->getRunningClient()->getCookieJar()->get($cookie, $params['path'], $params['domain']);
        if (!$cookies) {
            return null;
        }
        return $cookies->getValue();
    }

    public function seeCookie($cookie, array $params = [])
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->debugSection('Cookies', $this->client->getCookieJar()->all());
        $this->assertNotNull($this->client->getCookieJar()->get($cookie, $params['path'], $params['domain']));
    }

    public function dontSeeCookie($cookie, array $params = [])
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->debugSection('Cookies', $this->client->getCookieJar()->all());
        $this->assertNull($this->client->getCookieJar()->get($cookie, $params['path'], $params['domain']));
    }

    public function resetCookie($name, array $params = [])
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->client->getCookieJar()->expire($name, $params['path'], $params['domain']);
        $this->debugSection('Cookies', $this->client->getCookieJar()->all());
    }

    private function stringifySelector($selector)
    {
        if (is_array($selector)) {
            return trim(json_encode($selector), '{}');
        }
        return $selector;
    }

    public function seeElement($selector, $attributes = [])
    {
        $nodes = $this->match($selector);
        $selector = $this->stringifySelector($selector);
        if (!empty($attributes)) {
            $nodes = $this->filterByAttributes($nodes, $attributes);
            $selector .= "' with attribute(s) '" . trim(json_encode($attributes), '{}');
        }
        $this->assertDomContains($nodes, $selector);
    }

    public function dontSeeElement($selector, $attributes = [])
    {
        $nodes = $this->match($selector);
        $selector = $this->stringifySelector($selector);
        if (!empty($attributes)) {
            $nodes = $this->filterByAttributes($nodes, $attributes);
            $selector .= "' with attribute(s) '" . trim(json_encode($attributes), '{}');
        }
        $this->assertDomNotContains($nodes, $selector);
    }

    public function seeNumberOfElements($selector, $expected)
    {
        $counted = count($this->match($selector));
        if (is_array($expected)) {
            list($floor, $ceil) = $expected;
            $this->assertTrue(
                $floor <= $counted && $ceil >= $counted,
                'Number of elements counted differs from expected range'
            );
        } else {
            $this->assertEquals(
                $expected, $counted,
                'Number of elements counted differs from expected number'
            );
        }
    }

    public function seeOptionIsSelected($selector, $optionText)
    {
        $selected = $this->matchSelectedOption($selector);
        $this->assertDomContains($selected, 'selected option');
        //If element is radio then we need to check value
        $value = $selected->getNode(0)->tagName == 'option' ? $selected->text() : $selected->getNode(0)->getAttribute('value');
        $this->assertEquals($optionText, $value);
    }

    public function dontSeeOptionIsSelected($selector, $optionText)
    {
        $selected = $this->matchSelectedOption($selector);
        if (!$selected->count()) {
            $this->assertEquals(0, $selected->count());
            return;
        }
        //If element is radio then we need to check value
        $value = $selected->getNode(0)->tagName == 'option' ? $selected->text() : $selected->getNode(0)->getAttribute('value');
        $this->assertNotEquals($optionText, $value);
    }

    protected function matchSelectedOption($select)
    {
        $nodes = $this->getFieldsByLabelOrCss($select);
        return $nodes->filter('option[selected],input:checked');
    }

    /**
     * Asserts that current page has 404 response status code.
     */
    public function seePageNotFound()
    {
        $this->seeResponseCodeIs(404);
    }

    /**
     * Checks that response code is equal to value provided.
     *
     * @param $code
     *
     * @return mixed
     */
    public function seeResponseCodeIs($code)
    {
        $this->assertEquals($code, $this->getResponseStatusCode());
    }

    public function seeInTitle($title)
    {
        $nodes = $this->getCrawler()->filter('title');
        if (!$nodes->count()) {
            throw new ElementNotFound("<title>", "Tag");
        }
        $this->assertContains($title, $nodes->first()->text(), "page title contains $title");
    }

    public function dontSeeInTitle($title)
    {
        $nodes = $this->getCrawler()->filter('title');
        if (!$nodes->count()) {
            $this->assertTrue(true);
            return;
        }
        $this->assertNotContains($title, $nodes->first()->text(), "page title contains $title");
    }

    protected function assertDomContains($nodes, $message, $text = '')
    {
        $constraint = new CrawlerConstraint($text, $this->_getCurrentUri());
        $this->assertThat($nodes, $constraint, $message);
    }

    protected function assertDomNotContains($nodes, $message, $text = '')
    {
        $constraint = new CrawlerNotConstraint($text, $this->_getCurrentUri());
        $this->assertThat($nodes, $constraint, $message);
    }

    protected function assertPageContains($needle, $message = '')
    {
        $constraint = new PageConstraint($needle, $this->_getCurrentUri());
        $this->assertThat(
            html_entity_decode(strip_tags($this->getRunningClient()->getInternalResponse()->getContent()), ENT_QUOTES),
            $constraint,
            $message
        );
    }

    protected function assertPageNotContains($needle, $message = '')
    {
        $constraint = new PageConstraint($needle, $this->_getCurrentUri());
        $this->assertThatItsNot(
            html_entity_decode(strip_tags($this->getRunningClient()->getInternalResponse()->getContent()), ENT_QUOTES),
            $constraint,
            $message
        );
    }

    /**
     * @param $name
     * @param $form
     * @param $dynamicField
     * @return FormField
     */
    protected function matchFormField($name, $form, $dynamicField)
    {
        if (substr($name, -2) != '[]') {
            return $form[$name];
        }
        $name = substr($name, 0, -2);
        /** @var $item \Symfony\Component\DomCrawler\Field\FormField */
        foreach ($form[$name] as $item) {
            if ($item == $dynamicField) {
                return $item;
            }
        }
        throw new TestRuntimeException("None of form fields by {$name}[] were not matched");
    }

    /**
     * @param $locator
     * @return Crawler
     */
    protected function filterByCSS($locator)
    {
        if (!Locator::isCSS($locator)) {
            throw new MalformedLocatorException($locator, 'css');
        }
        return $this->getCrawler()->filter($locator);
    }

    /**
     * @param $locator
     * @return Crawler
     */
    protected function filterByXPath($locator)
    {
        if (!Locator::isXPath($locator)) {
            throw new MalformedLocatorException($locator, 'xpath');
        }
        return $this->getCrawler()->filterXPath($locator);
    }

    /**
     * @param $requestParams
     * @return array
     */
    protected function getFormPhpValues($requestParams)
    {
        foreach ($requestParams as $name => $value) {
            $qs = http_build_query([$name => $value], '', '&');
            if (!empty($qs)) {
                parse_str($qs, $expandedValue);
                $varName = substr($name, 0, strlen(key($expandedValue)));
                $requestParams = array_replace_recursive($requestParams, [$varName => current($expandedValue)]);
            }
        }
        return $requestParams;
    }

    /**
     * @param $result
     * @return mixed
     */
    protected function redirectIfNecessary($result, $maxRedirects, $redirectCount)
    {
        $locationHeader = $this->client->getInternalResponse()->getHeader('Location');
        if ($locationHeader) {
            if ($redirectCount == $maxRedirects) {
                throw new \LogicException(sprintf('The maximum number (%d) of redirections was reached.', $maxRedirects));
            }

            $this->debugSection('Redirecting to', $locationHeader);

            $result = $this->client->followRedirect();
            $this->debugResponse($locationHeader);
            return $this->redirectIfNecessary($result, $maxRedirects, $redirectCount + 1);
        }
        $this->client->followRedirects(true);
        return $result;
    }

    /**
     * Clicks on a given link.
     *
     * @param Link $link A Link instance
     *
     * @return Crawler
     */
    protected function clientClick(Link $link)
    {
        if ($link instanceof Form) {
            return $this->proceedSubmitForm($link);
        }

        return $this->clientRequest($link->getMethod(), $link->getUri());
    }

    /**
     * Switch to iframe or frame on the page.
     *
     * Example:
     * ``` html
     * <iframe name="another_frame" src="http://example.com">
     * ```
     *
     * ``` php
     * <?php
     * # switch to iframe
     * $I->switchToIframe("another_frame");
     * ```
     *
     * @param string $name
     */

    public function switchToIframe($name)
    {
        $iframe = $this->match("iframe[name=$name]")->first();
        if (!count($iframe)) {
            $iframe = $this->match("frame[name=$name]")->first();
        }
        if (!count($iframe)) {
            throw new ElementNotFound("name=$name", 'Iframe');
        }

        $uri = $iframe->getNode(0)->getAttribute('src');
        $this->amOnPage($uri);
    }

    /**
     * Moves back in history.
     * 
     * @param int $numberOfSteps (default value 1)
     */
    public function moveBack($numberOfSteps = 1)
    {
        if (!is_int($numberOfSteps) || $numberOfSteps < 1) {
            throw new \InvalidArgumentException('numberOfSteps must be positive integer');
        }
        try {
            $history = $this->getRunningClient()->getHistory();
            for ($i = $numberOfSteps; $i > 0; $i--) {
                $request = $history->back();
            }
        } catch (\LogicException $e) {
            throw new \InvalidArgumentException('numberOfSteps is set to ' . $numberOfSteps . ', but there are only ' . ($numberOfSteps - $i) . ' previous steps in the history');
        }
        $this->_loadPage($request->getMethod(),$request->getUri(), $request->getParameters(), $request->getFiles(), $request->getServer(), $request->getContent());
    }
}
