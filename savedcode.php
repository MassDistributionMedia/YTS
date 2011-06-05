    /**
     * Creates individual Entry objects of the appropriate type and
     * stores them in the $_entry array based upon DOM data.
     *
     * @param DOMNode $child The DOMNode to process
     */
    protected function takeChildFromDOM($child)
    {
        $absoluteNodeName = $child->namespaceURI . ':' . $child->localName;
        switch ($absoluteNodeName) {
        case $this->lookupNamespace('openSearch') . ':' . 'totalResults':
            $totalResults = new Zend_Gdata_Extension_OpenSearchTotalResults();
            $totalResults->transferFromDOM($child);
            $this->_totalResults = $totalResults;
            break;
        case $this->lookupNamespace('openSearch') . ':' . 'startIndex':
            $startIndex = new Zend_Gdata_Extension_OpenSearchStartIndex();
            $startIndex->transferFromDOM($child);
            $this->_startIndex = $startIndex;
            break;
        case $this->lookupNamespace('openSearch') . ':' . 'itemsPerPage':
            $itemsPerPage = new Zend_Gdata_Extension_OpenSearchItemsPerPage();
            $itemsPerPage->transferFromDOM($child);
            $this->_itemsPerPage = $itemsPerPage;
            break;
        case $this->lookupNamespace('atom') . ':' . 'author':
            $author = new Zend_Gdata_App_Extension_Author();
            $author->transferFromDOM($child);
            $this->_author[] = $author;
            break;
        case $this->lookupNamespace('atom') . ':' . 'category':
            $category = new Zend_Gdata_App_Extension_Category();
            $category->transferFromDOM($child);
            $this->_category[] = $category;
            break;
        case $this->lookupNamespace('atom') . ':' . 'contributor':
            $contributor = new Zend_Gdata_App_Extension_Contributor();
            $contributor->transferFromDOM($child);
            $this->_contributor[] = $contributor;
            break;
        case $this->lookupNamespace('atom') . ':' . 'id':
            $id = new Zend_Gdata_App_Extension_Id();
            $id->transferFromDOM($child);
            $this->_id = $id;
            break;
        case $this->lookupNamespace('atom') . ':' . 'link':
            $link = new Zend_Gdata_App_Extension_Link();
            $link->transferFromDOM($child);
            $this->_link[] = $link;
            break;
        case $this->lookupNamespace('atom') . ':' . 'rights':
            $rights = new Zend_Gdata_App_Extension_Rights();
            $rights->transferFromDOM($child);
            $this->_rights = $rights;
            break;
        case $this->lookupNamespace('atom') . ':' . 'title':
            $title = new Zend_Gdata_App_Extension_Title();
            $title->transferFromDOM($child);
            $this->_title = $title;
            break;
        case $this->lookupNamespace('atom') . ':' . 'updated':
            $updated = new Zend_Gdata_App_Extension_Updated();
            $updated->transferFromDOM($child);
            $this->_updated = $updated;
            break;
        case $this->lookupNamespace('atom') . ':' . 'entry':
            $newEntry = new $this->_entryClassName($child);
            $newEntry->setHttpClient($this->getHttpClient());
            $newEntry->setMajorProtocolVersion($this->getMajorProtocolVersion());
            $newEntry->setMinorProtocolVersion($this->getMinorProtocolVersion());
            $this->_entry[] = $newEntry;
            break;
        default:
            if ($child->nodeType == XML_TEXT_NODE) {
                $this->_text = $child->nodeValue;
            } else {
                $extensionElement = new Zend_Gdata_App_Extension_Element();
                $extensionElement->transferFromDOM($child);
                $this->_extensionElements[] = $extensionElement;
            }
            break;
        }
    }

    /**
     * Given a DOMNode representing an attribute, tries to map the data into
     * instance members.  If no mapping is defined, the name and value are
     * stored in an array.
     *
     * @param DOMNode $attribute The DOMNode attribute needed to be handled
     */
    protected function takeAttributeFromDOM($attribute)
    {
        switch ($attribute->localName) {
        case 'etag':
            // ETags are special, since they can be conveyed by either the
            // HTTP ETag header or as an XML attribute.
            $etag = $attribute->nodeValue;
            if ($this->_etag === null) {
                $this->_etag = $etag;
            }
            elseif ($this->_etag != $etag) {
                require_once('Zend/Gdata/App/IOException.php');
                throw new Zend_Gdata_App_IOException("ETag mismatch");
            }
            break;
        default:
            $arrayIndex = ($attribute->namespaceURI != '')?(
                    $attribute->namespaceURI . ':' . $attribute->name):
                    $attribute->name;
            $this->_extensionAttributes[$arrayIndex] =
                    array('namespaceUri' => $attribute->namespaceURI,
                          'name' => $attribute->localName,
                          'value' => $attribute->nodeValue);
            break;
        }
    }

    /**
     * Transfers each child and attribute into member variables.
     * This is called when XML is received over the wire and the data
     * model needs to be built to represent this XML.
     *
     * @param DOMNode $node The DOMNode that represents this object's data
     */
    public function transferFromDOM($node)
    {
        foreach ($node->childNodes as $child) {
            $this->takeChildFromDOM($child);
        }
        foreach ($node->attributes as $attribute) {
            $this->takeAttributeFromDOM($attribute);
        }
    }

    /**
     * Parses the provided XML text and generates data model classes for
     * each know element by turning the XML text into a DOM tree and calling
     * transferFromDOM($element).  The first data model element with the same
     * name as $this->_rootElement is used and the child elements are
     * recursively parsed.
     *
     * @param string $xml The XML text to parse
     */
    public function transferFromXML($xml)
    {
        if ($xml) {
            // Load the feed as an XML DOMDocument object
            @ini_set('track_errors', 1);
            $doc = new DOMDocument();
            $success = @$doc->loadXML($xml);
            @ini_restore('track_errors');
            if (!$success) {
                require_once 'Zend/Gdata/App/Exception.php';
                throw new Zend_Gdata_App_Exception("DOMDocument cannot parse XML: $php_errormsg");
            }
            $element = $doc->getElementsByTagName($this->_rootElement)->item(0);
            if (!$element) {
                require_once 'Zend/Gdata/App/Exception.php';
                throw new Zend_Gdata_App_Exception('No root <' . $this->_rootElement . '> element');
            }
            $this->transferFromDOM($element);
        } else {
            require_once 'Zend/Gdata/App/Exception.php';
            throw new Zend_Gdata_App_Exception('XML passed to transferFromXML cannot be null');
        }
    }