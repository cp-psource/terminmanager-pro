<?php
/*
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not
 * use this file except in compliance with the License. You may obtain a copy of
 * the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations under
 * the License.
 */

/**
 * Service definition for Webfonts (v1).
 *
 * <p>
 * The Google Fonts Developer API.</p>
 *
 * <p>
 * For more information about this service, see the API
 * <a href="https://developers.google.com/fonts/docs/developer_api" target="_blank">Documentation</a>
 * </p>
 *
 * @author Google, Inc.
 */
class Appointments_Google_Service_Webfonts extends Appointments_Google_Service
{


  public $webfonts;
  

  /**
   * Constructs the internal representation of the Webfonts service.
   *
   * @param Appointments_Google_Client $client
   */
  public function __construct(Appointments_Google_Client $client)
  {
    parent::__construct($client);
    $this->rootUrl = 'https://www.googleapis.com/';
    $this->servicePath = 'webfonts/v1/';
    $this->version = 'v1';
    $this->serviceName = 'webfonts';

    $this->webfonts = new Appointments_Google_Service_Webfonts_Webfonts_Resource(
        $this,
        $this->serviceName,
        'webfonts',
        array(
          'methods' => array(
            'list' => array(
              'path' => 'webfonts',
              'httpMethod' => 'GET',
              'parameters' => array(
                'sort' => array(
                  'location' => 'query',
                  'type' => 'string',
                ),
              ),
            ),
          )
        )
    );
  }
}


/**
 * The "webfonts" collection of methods.
 * Typical usage is:
 *  <code>
 *   $webfontsService = new Appointments_Google_Service_Webfonts(...);
 *   $webfonts = $webfontsService->webfonts;
 *  </code>
 */
class Appointments_Google_Service_Webfonts_Webfonts_Resource extends Appointments_Google_Service_Resource
{

  /**
   * Retrieves the list of fonts currently served by the Google Fonts Developer
   * API (webfonts.listWebfonts)
   *
   * @param array $optParams Optional parameters.
   *
   * @opt_param string sort Enables sorting of the list
   * @return Appointments_Google_Service_Webfonts_WebfontList
   */
  public function listWebfonts($optParams = array())
  {
    $params = array();
    $params = array_merge($params, $optParams);
    return $this->call('list', array($params), "Appointments_Google_Service_Webfonts_WebfontList");
  }
}




class Appointments_Google_Service_Webfonts_Webfont extends Appointments_Google_Collection
{
  protected $collection_key = 'variants';
  protected $internal_gapi_mappings = array(
  );
  public $category;
  public $family;
  public $files;
  public $kind;
  public $lastModified;
  public $subsets;
  public $variants;
  public $version;


  public function setCategory($category)
  {
    $this->category = $category;
  }
  public function getCategory()
  {
    return $this->category;
  }
  public function setFamily($family)
  {
    $this->family = $family;
  }
  public function getFamily()
  {
    return $this->family;
  }
  public function setFiles($files)
  {
    $this->files = $files;
  }
  public function getFiles()
  {
    return $this->files;
  }
  public function setKind($kind)
  {
    $this->kind = $kind;
  }
  public function getKind()
  {
    return $this->kind;
  }
  public function setLastModified($lastModified)
  {
    $this->lastModified = $lastModified;
  }
  public function getLastModified()
  {
    return $this->lastModified;
  }
  public function setSubsets($subsets)
  {
    $this->subsets = $subsets;
  }
  public function getSubsets()
  {
    return $this->subsets;
  }
  public function setVariants($variants)
  {
    $this->variants = $variants;
  }
  public function getVariants()
  {
    return $this->variants;
  }
  public function setVersion($version)
  {
    $this->version = $version;
  }
  public function getVersion()
  {
    return $this->version;
  }
}

class Appointments_Google_Service_Webfonts_WebfontList extends Appointments_Google_Collection
{
  protected $collection_key = 'items';
  protected $internal_gapi_mappings = array(
  );
  protected $itemsType = 'Appointments_Google_Service_Webfonts_Webfont';
  protected $itemsDataType = 'array';
  public $kind;


  public function setItems($items)
  {
    $this->items = $items;
  }
  public function getItems()
  {
    return $this->items;
  }
  public function setKind($kind)
  {
    $this->kind = $kind;
  }
  public function getKind()
  {
    return $this->kind;
  }
}