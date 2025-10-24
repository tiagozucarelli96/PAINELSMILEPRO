<?php
/**
 * config_local.php — Configurações locais
 */

// Configurações de banco local
$localDbConfig = [
    "host" => "localhost",
    "port" => "5432",
    "dbname" => "painel_smile",
    "user" => "tiagozucarelli",
    "password" => ""
];

// Função para obter conexão local
function getLocalDbConnection() {
    global $localDbConfig;
    
    $dsn = "pgsql:host={$localDbConfig["host"]};port={$localDbConfig["port"]};dbname={$localDbConfig["dbname"]}";
    
    return new PDO($dsn, $localDbConfig["user"], $localDbConfig["password"], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

// Definir variáveis de ambiente para o sistema
putenv("DATABASE_URL=" . $localDbConfig["host"] . ":" . $localDbConfig["port"] . "/" . $localDbConfig["dbname"]);
putenv("DB_SCHEMA=public");
putenv("APP_DEBUG=1");
?>