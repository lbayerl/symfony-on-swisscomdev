<?php

$vcapServices = json_decode(getenv('VCAP_SERVICES'));

$container->setParameter('database_driver', 'pdo_mysql');

$db = $vcapServices->{'mariadb'}[0]->credentials;

$container->setParameter('database_host', $db->host);
$container->setParameter('database_port', $db->port);
$container->setParameter('database_name', $db->name);
$container->setParameter('database_user', $db->username);
$container->setParameter('database_password', $db->password);
