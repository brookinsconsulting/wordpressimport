#!/usr/bin/env php
<?php

include_once( 'kernel/classes/ezscript.php' );
include_once( 'lib/ezutils/classes/ezcli.php' );

$cli =& eZCLI::instance();

$scriptSettings = array();
$scriptSettings['description'] = 'Migrate your Wordpress blog to eZ publish';
$scriptSettings['use-session'] = true;
$scriptSettings['use-modules'] = true;
$scriptSettings['use-extensions'] = true;

$script =& eZScript::instance( $scriptSettings );
$script->startup();

$config = '[host:][user:][password:][database:]';
$argumentConfig = '[wpfilepath]';
$optionHelp = array( 'host' => 'Connect to database host',
                     'user' => 'User for login to the database',
                     'password' => 'Password to use for login to the database',
                     'database' => 'Wordpress database' );

$arguments = false;
$useStandardOptions = true;

$options = $script->getOptions( $config, $argumentConfig, $optionHelp, $arguments, $useStandardOptions );
$script->initialize();

// log in as admin
include_once( 'kernel/classes/datatypes/ezuser/ezuser.php' );
$user = eZUser::fetchByName( 'admin' );
$userID = $user->attribute( 'contentobject_id' );
eZUser::setCurrentlyLoggedInUser( $user, $userID );

if ( count( $options['arguments'] ) != 1 )
{
    $script->shutdown( 1, 'Wrong argument count' );
}

$wpFilePath = $options['arguments'][0];

$host = is_string( $options['host'] ) ? $options['host'] : 'localhost';
$user = is_string( $options['user'] ) ? $options['user'] : 'root';
$password = is_string( $options['password'] ) ? $options['password'] : '';

if ( !is_string( $options['database'] ) )
{
    $cli->error( 'Missing option: database' );
    $script->shutdown( 1 );
}

$database = $options['database'];

$db =& eZDB::instance(
    'mysql',
    array( 'server' => $host,
          'user' => $user,
          'password' => $password,
          'database' => $database ),
    true
);

if ( !is_object( $db ) )
{
    $cli->error( 'Could not initialize database:' );
    $cli->error( '* No database handler was found for mysql' );
    $script->shutdown( 2 );
}

if ( !$db or !$db->isConnected() )
{
    $cli->error( 'Could not initialize database.' );

    $msg = $db->errorMessage();
    if ( $msg )
    {
        $number = $db->errorNumber();
        if ( $number > 0 )
            $msg .= '(' . $number . ')';
        $cli->error( '* ' . $msg );
    }

    $script->shutdown( 3 );
}

$attachments = $db->arrayQuery( "SELECT ID, UNIX_TIMESTAMP(post_date) as post_date_timestamp, post_title, post_content, post_excerpt, UNIX_TIMESTAMP(post_modified) as post_modified_timestamp, post_name, guid FROM wp_posts WHERE post_status='attachment'" );

$attachmentIDMap = array();
$attachmentPathIDMap = array();

// START temporary workaround for bug http://issues.ez.no/IssueView.php?Id=9291
include_once( 'lib/ezutils/classes/ezhttptool.php' );
include_once( 'lib/ezutils/classes/ezini.php' );
include_once( 'kernel/classes/ezcontentclass.php' );
// END temporary workaround for bug http://issues.ez.no/IssueView.php?Id=9291

include_once( 'kernel/classes/ezcontentupload.php' );
$upload = new eZContentUpload();

foreach ( $attachments as $att )
{
    $url = parse_url( $att['guid'] );

    $attachmentIDMap[$att['ID']] = $att['guid'];
    $attachmentPathIDMap[$att['ID']] = $url['path'];

    //$filePath = 'file://' . str_replace( '/', '\\', $wpFilePath . $url['path'] );
    //$filePath = 'file://' . $wpFilePath . $url['path'];
    $filePath = $wpFilePath . $url['path'];

    $cli->output( $filePath );
/*
    $success = $upload->handleLocalFile( $result, $filePath, 'auto', false, $att['post_name'] );
    if ( $success )
    {
        $objectID= $result['contentobject_id'];
        include_once( 'kernel/classes/ezcontentobject.php' );
        $object =& eZContentObject::fetch( $objectID );
        $object->setAttribute( 'remote_id', 'wordpress_import_' . $att['ID'] );
        $object->setAttribute( 'modified', $att['post_modified_timestamp'] );
        $object->setAttribute( 'published', $att['post_date_timestamp'] );
        $object->store();

        $version =& $object->currentVersion();
        $version->setAttribute( 'created', $att['post_modified_timestamp'] );
        $version->setAttribute( 'modified', $att['post_modified_timestamp'] );
        $version->store();

        $source = $url['path'];
        $destination = '/content/view/full/' . $object->attribute( 'main_node_id' );
        include_once( 'kernel/classes/ezurlalias.php' );
        $alias = eZURLAlias::create( $source, $destination, false );
        $alias->store();
    }
    else
    {
        $cli->error( 'Storing ' . $filePath . ' failed.' );
    }*/
}

$articles = $db->arrayQuery( "SELECT ID, UNIX_TIMESTAMP(post_date) as post_date_timestamp, post_title, post_content, post_excerpt, UNIX_TIMESTAMP(post_modified) as post_modified_timestamp, post_name, guid FROM wp_posts WHERE post_status='publish' ORDER BY post_date ASC" );

$contentClassIdentifier = 'blog';
$parentNodeID = 414;

$postIDMap = array();

foreach ( $articles as $art )
{
    $url = parse_url( $art['guid'] );

    $timestamp = $art['post_date_timestamp'];
    $timeParts = getdate( $timestamp );

    // replace old guid structure
    if ( trim( $url['path'] ) == '' )
    {
        $url['path'] = implode( '/', array( $timeParts['year'], $timeParts['mon'], $timeParts['mday'], $art['post_name'] ) );
    }

    $postIDMap[$art['ID']] = $url['path'];

    $content = $art['post_content'];
    $newContent = convertToXMLText( $content, $cli, $attachmentIDMap, $attachmentPathIDMap, $postIDMap );

    //$cli->output( $newContent );

    $leader = '';
    if ( trim( $art['post_excerpt'] ) != '' )
    {
        $leader = convertToXMLText( trim( $art['post_excerpt'] ), $cli, $attachmentIDMap, $attachmentPathIDMap, $postIDMap );
    }

    $attributeValues = array( 'title' => $art['post_title'], 'body' => $newContent );
    if ( $leader != '' )
    {
        $attributeValues['leader'] = $leader;
    }

    $cli->output( 'publishing ' . $art['post_title'] );

    $result = publish( $parentNodeID, $contentClassIdentifier, $attributeValues );

    if ( !is_array( $result )  )
    {
        $cli->output( 'failed publishing ' . $art['post_title'] );
    }
    else
    {
        // add url alias
        include_once( 'kernel/classes/ezcontentobject.php' );
        $object = eZContentObject::fetch( $result['contentobject_id'] );
        $mainNodeID = $object->attribute( 'main_node_id' );

        $url = parse_url( $art['guid'] );
        $source = $url['path'];
        $destination = '/content/view/full/' . $mainNodeID;
        include_once( 'kernel/classes/ezurlalias.php' );
        $alias = eZURLAlias::create( $source, $destination, false );
        $alias->store();

        $object->setAttribute( 'remote_id', 'wordpress_import_' . $art['ID'] );
        $object->setAttribute( 'modified', $art['post_modified_timestamp'] );
        $object->setAttribute( 'published', $art['post_date_timestamp'] );
        $object->store();

        $version =& $object->currentVersion();
        $version->setAttribute( 'created', $art['post_modified_timestamp'] );
        $version->setAttribute( 'modified', $art['post_modified_timestamp'] );
        $version->store();
    }
}

function convertToXMLText( $content, &$cli, $attachmentIDMap, $attachmentPathIDMap, $postIDMap = array() )
{
    $matches = array();
    $pattern = '/<img\s[^>]*src="([^"]*)"[^>]*>/is';
    $newContent = $content;
    preg_match_all( $pattern, $content, $matches );

    if ( count( $matches[1] ) > 0 )
    {
        $toReplace = array();
        $replacements = array();
        foreach ( $matches[1] as $key => $match )
        {
            $attachmentID = false;
            //$cli->output( substr( $match, -14, 14 ) );
            if ( substr( $match, -14, 14 ) == '.thumbnail.jpg' )
            {
                $match = substr( $match, 0, strlen( $match ) - 14 ) . '.jpg';
            }

            $cli->output( 'searching ezxml replacement for image ' . $match );
            $attachmentID = array_search( $match, $attachmentIDMap );

            if ( !$attachmentID )
            {
                $attachmentID = array_search( $match, $attachmentPathIDMap );
            }

            if ( !$attachmentID )
            {
                eZDebug::writeError( 'could not find attachment ' . $match );
                //eZDebug::writeDebug( $attachmentIDMap );
                //eZDebug::writeDebug( $attachmentPathIDMap );
                //eZDebug::writeDebug( $postIDMap );
                continue;
            }

            include_once( 'kernel/classes/ezcontentobject.php' );
            $relatedObject = eZContentObject::fetchByRemoteID( 'wordpress_import_' . $attachmentID );
            if ( !$relatedObject )
            {
                eZDebug::writeError( 'could not find attachment object ' . $match . ' with id ' . $attachmentID );
                continue;
            }

            $toReplace[] = $matches[0][$key];
            $replacements[] = '<embed href="ezobject://' . $relatedObject->attribute( 'id' ) . '" size="small" />';
        }

        $newContent = str_replace( $toReplace, $replacements, $newContent );
    }

    // match local links
    $matches = array();
    $pattern = '#<a\s[^>]*href="(/[^"]*)"[^>]*>#is';
    preg_match_all( $pattern, $newContent, $matches );

    if ( count( $matches[1] ) > 0 )
    {
        $toReplace = array();
        $replacements = array();
        foreach ( $matches[1] as $key => $match )
        {
            $wpID = false;

            $cli->output( 'searching ezxml replacement for link ' . $match );
            $wpID = array_search( $match, $attachmentIDMap );

            if ( !$wpID )
            {
                $wpID = array_search( $match, $attachmentPathIDMap );
            }

            if ( !$wpID )
            {
                $wpID = array_search( $match, $postIDMap );
            }

            if ( !$wpID )
            {
                eZDebug::writeError( 'could not find wordpress object ' . $match );
                //eZDebug::writeDebug( $attachmentIDMap );
                //eZDebug::writeDebug( $attachmentPathIDMap );
                //eZDebug::writeDebug( $postIDMap );
                continue;
            }

            // when debugging is done replace the ID in the attachmentIDMap with the node id instead of the wordpress id
            include_once( 'kernel/classes/ezcontentobject.php' );
            $relatedObject = eZContentObject::fetchByRemoteID( 'wordpress_import_' . $wpID );
            if ( !$relatedObject )
            {
                eZDebug::writeError( 'could not find wordpress object ' . $match . ' with id ' . $wpID );
                continue;
            }
            else
            {
                eZDebug::writeDebug( 'found link replacement for ' . $match );
            }

            $toReplace[] = 'href="' . $matches[1][$key] . '"';
            $replacements[] = 'href="ezobject://' . $relatedObject->attribute( 'id' ) . '"';
        }

        $newContent = str_replace( $toReplace, $replacements, $newContent );
    }

    $newContent = str_replace( array( '<pre>', '</pre>', '<!--more-->', '<code>', '</code>' ), array( '<literal>', '</literal>', '', '<em>', '</em>' ), $newContent );

    $newContent = html_entity_decode( $newContent, ENT_NOQUOTES );

    return $newContent;
}

function publish( $parentNodeID, $contentClassIdentifier, $attributeValues  )
{
    include_once( 'kernel/classes/ezcontentobjecttreenode.php' );
    $parentNode = eZContentObjectTreeNode::fetch( $parentNodeID );

    include_once( 'kernel/classes/ezcontentclass.php' );
    $class = eZContentClass::fetchByIdentifier( $contentClassIdentifier );
    if ( !is_object( $class ) )
    {
        eZDebug::writeError( 'could not fetch class with identifier ' . $contentClassIdentifier );
        return false;
    }

    $parentObject   = $parentNode->attribute( 'object' );
    $sectionID      = $parentObject->attribute( 'section_id' );
    $ownerID        = $parentObject->attribute( 'owner_id' );
    $contentClassID = $class->attribute( 'id' );

    include_once( 'lib/ezdb/classes/ezdb.php' );
    $db =& eZDB::instance();
    $db->begin();

    $contentObject =& $class->instantiate( $ownerID, $sectionID );
    $nodeAssignment = eZNodeAssignment::create( array( 'contentobject_id' => $contentObject->attribute( 'id' ),
                                                       'contentobject_version' => $contentObject->attribute( 'current_version' ),
                                                       'parent_node' => $parentNode->attribute( 'node_id' ),
                                                       'is_main' => 1 ) );

    $nodeAssignment->store();

    $time = mktime();

    $contentObject->setAttribute( 'modified', $time );
    $version = $contentObject->currentVersion();

    $attribs =& $version->contentObjectAttributes();
    $attribsCount = count( $attribs );

    for ( $i = 0; $i < $attribsCount; $i++ )
    {
        $identifier = $attribs[$i]->attribute( 'contentclass_attribute_identifier' );

        if ( array_key_exists( $identifier, $attributeValues ) )
        {
            $contentClassAttribute =& $attribs[$i]->attribute( 'contentclass_attribute' );
            $value = $attributeValues[$identifier];
            $datatype = $contentClassAttribute->attribute( 'data_type' );

            $result = false;

            if ( $datatype->isSimpleStringInsertionSupported() )
            {
                $attribs[$i]->insertSimpleString( $contentObject, false, false, $value, $result );
                $attribs[$i]->store();
            }
            else if ( $datatype->isRegularFileInsertionSupported() )
            {
                $attribs[$i]->insertRegularFile( $contentObject, false, false, $value, $result );
                $attribs[$i]->store();
            }
            else if ( $attribs[$i]->attribute( 'data_type_string' ) == 'ezkeyword' )
            {
                include_once( 'kernel/classes/datatypes/ezkeyword/ezkeyword.php' );
                $keyword = new eZKeyword();
                $keyword->initializeKeyword( implode( ', ', $value ) );
                $attribs[$i]->setContent( $keyword );
                $attribs[$i]->store();
            }
            else if ( $attribs[$i]->attribute( 'data_type_string' ) == 'ezurl' &&
                      is_array( $value ) && array_key_exists( 'url', $value ) && array_key_exists( 'text', $value ) )
            {
                $attribs[$i]->setContent( $value['url'] );
                $attribs[$i]->setAttribute( 'data_text', $value['text'] );

                $attribs[$i]->store();
            }
            elseif ( $attribs[$i]->attribute( 'data_type_string' ) == 'ezxmltext' )
            {
                include_once( 'kernel/classes/datatypes/ezxmltext/handlers/input/ezsimplifiedxmlinput.php' );
                include_once( 'kernel/classes/datatypes/ezxmltext/handlers/input/ezsimplifiedxmlinputparser.php' );

                $parser = new eZSimplifiedXMLInputParser( $contentObject->attribute( 'id' ) );
                $parser->setParseLineBreaks( true );
                $document = $parser->process( $value );

                if ( !is_object( $document ) )
                {
                    echo "no dom document returned by xml parser\r\n";

                    $errors = $parser->getMessages();
                    foreach ( $errors as $error )
                    {
                        echo '* ' . $error . "\r\n";
                    }

                    $db->rollback();
                    return false;
                }

                $xmlString = eZXMLTextType::domString( $document );

                //eZDebug::writeDebug( $xmlString, '$xmlString' );

                $relatedObjectIDArray = $parser->getRelatedObjectIDArray();
                $urlIDArray = $parser->getUrlIDArray();

                if ( count( $urlIDArray ) > 0 )
                {
                    include_once( 'kernel/classes/datatypes/ezxmltext/handlers/input/ezsimplifiedxmlinput.php' );
                    eZSimplifiedXMLInput::updateUrlObjectLinks( $attribs[$i], $urlIDArray );
                }

                if ( count( $relatedObjectIDArray ) > 0 )
                {
                    include_once( 'kernel/classes/datatypes/ezxmltext/handlers/input/ezsimplifiedxmlinput.php' );
                    eZSimplifiedXMLInput::updateRelatedObjectsList( $attribs[$i], $relatedObjectIDArray );
                }

                $xmlString = eZXMLTextType::domString( $document );

                $attribs[$i]->setAttribute( 'data_text', $xmlString );
                $attribs[$i]->store();
            }
        }
        else
        {
            //eZDebug::writeDebug( 'attribute value not specified for: ' . $identifier );
        }
    }

    include_once( 'lib/ezutils/classes/ezoperationhandler.php' );

    $operationParams = array();
    $operationParams['object_id']   = $contentObject->attribute( 'id' );
    $operationParams['version']     = $contentObject->attribute( 'current_version' );

    $operationResult = eZOperationHandler::execute( 'content', 'publish', $operationParams );
    $db->commit();

    // when preview cache is on, the user is restored but the policy limitations are still wrongly cached
    // see http://ez.no/community/bugs/cache_for_content_read_limitation_list_isn_t_cleared_after_switching_users
    // this is a temporary workaround, until the kernel has been fixed
    if ( isset( $GLOBALS['ezpolicylimitation_list']['content']['read'] ) )
    {
        unset( $GLOBALS['ezpolicylimitation_list']['content']['read'] );
    }

    return array( 'contentobject_id' => $contentObject->attribute( 'id' ) );
}

$script->shutdown( 0 );

?>