<?php

$ini = eZINI::instance( 'dbi_contentreviewdate.ini' );
$rootNodeIDList = $ini->variable( 'ReviewNotification','RootNodeList' );
$attributeArray = $ini->variable( 'ReviewNotification', 'ReviewDateAttributeList' );
$fallbackEmail = $ini->variable( 'ReviewNotification', 'FallbackEmail' );

if ( $fallbackEmail === '' || eZMail::validate( $fallbackEmail ) == false )
{
    eZDebug::writeWarning( 'there is no fallback e-mail address specified in dbi_contentreviewdate.ini or the one specified is not valid' );
    $fallbackEmail = false;
}

$classes = array_keys( $attributeArray );

$siteIni = eZINI::instance( 'site.ini' );
$emailSender = $siteIni->variable( 'MailSettings', 'EmailSender' );
if ( !$emailSender )
{
    $emailSender = $siteIni->variable( 'MailSettings', 'AdminEmail' );
}

$currentDate = time();

$offset = 0;
$limit = 20;

foreach ( $rootNodeIDList as $nodeID )
{
    $rootNode = eZContentObjectTreeNode::fetch( $nodeID );

    while( true )
    {
        $nodeArray = $rootNode->subTree( array( 'ClassFilterType' => 'include',
                                                 'ClassFilterArray' => $classes,
                                                 'Offset' => $offset,
                                                 'Limit' => $limit,
                                                 'Limitation' => array() ) );
        if ( !is_array( $nodeArray ) || count( $nodeArray ) == 0 )
        {
            break;
        }

        $offset += $limit;

        foreach ( array_keys( $nodeArray ) as $key )
        {
            $node = $nodeArray[$key];
            $dataMap = $node->attribute( 'data_map' );

            $dateAttributeName = $attributeArray[$node->attribute( 'class_identifier' )];

            if ( !$dateAttributeName )
            {
                continue;
            }
            else
            {
                $cli->output( 'matching class found: ' . $node->attribute( 'class_identifier' ) );
            }

            $dateAttribute = $dataMap[$dateAttributeName];

            if ( is_null( $dateAttribute ) || !$dateAttribute->hasContent() )
            {
                continue;
            }
            else
            {
                $cli->output( 'matching attribute found: ' . $dateAttributeName );
            }

            $date = $dateAttribute->content();

            if ( $date->attribute( 'timestamp' ) > 0 && $date->isEqualTo( $currentDate ) )
            {
                // send out notification for this node to owner and last modifier

                $object = $node->attribute( 'object' );
                $currentVersion = $object->attribute( 'current' );

                $mail = new eZMail();
                $mail->setSender( $emailSender );

                $ownerID = $object->attribute( 'owner_id' );
                $owner = eZUser::fetch( $ownerID );

                $addFallback = false;
                if ( is_object( $owner ) && $owner->attribute( 'is_enabled' ) )
                {
                    $mail->addReceiver( $owner->attribute( 'email' ) );
                }
                else
                {
                    $addFallback = true;
                }

                $creatorID = $currentVersion->attribute( 'creator_id' );

                if ( $creatorID != $ownerID )
                {
                    $creator = eZUser::fetch( $creatorID );

                    if ( is_object( $creator ) && $creator->attribute( 'is_enabled' ) )
                    {
                        $mail->addReceiver( $creator->attribute( 'email' ) );
                    }
                    else
                    {
                        $addFallback = true;
                    }
                }

                if ( $addFallback && $fallbackEmail )
                {
                    $mail->addReceiver( $fallbackEmail );
                }

                $keyArray = array( array( 'object', $object->attribute( 'id' ) ),
                   array( 'node', $node->attribute( 'node_id' ) ),
                   array( 'parent_node', $node->attribute( 'parent_node_id' ) ),
                   array( 'class', $object->attribute( 'contentclass_id' ) ),
                   array( 'class_identifier', $node->attribute( 'class_identifier' ) ),
                   array( 'depth', $node->attribute( 'depth' ) ),
                   array( 'url_alias', $node->attribute( 'url_alias' ) ),
                   array( 'class_group', $object->attribute( 'match_ingroup_id_list' ) ) );

                require_once( 'kernel/common/template.php' );
                $tpl = templateInit();
                $res = eZTemplateDesignResource::instance();
                $res->setKeys( $keyArray );

                $tpl->resetVariables();
                $tpl->setVariable( 'node', $node );
                $message = $tpl->fetch( 'design:cronjobs/contentreviewnotify.tpl' );

                $subject = $tpl->hasVariable( 'subject' ) ? $tpl->variable( 'subject' ) : 'Content review needed';
                $mail->setSubject( $subject );
                $mail->setBody( $message );
                $mailResult = eZMailTransport::send( $mail );

                $cli->output( 'mail send for ' . $node->attribute( 'url_alias' ) );
            }
        }
    }
}

?>