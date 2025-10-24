<?php
/**
 * setup_local_env.php — Configurar variáveis de ambiente locais
 * Execute: php setup_local_env.php
 */

echo "🔧 Configurando Variáveis de Ambiente Locais\n";
echo "============================================\n\n";

// Configurações locais (baseadas no que criamos)
$localConfig = [
    'DATABASE_URL' => 'postgres://tiagozucarelli@localhost:5432/painel_smile',
    'DB_SCHEMA' => 'public',
    'APP_DEBUG' => '1'
];

echo "📋 Configurações que serão definidas:\n";
foreach ($localConfig as $key => $value) {
    echo "  $key = $value\n";
}

echo "\n🔍 Verificando se o arquivo .env existe...\n";

$envFile = __DIR__ . '/.env';
$envContent = '';

// Ler arquivo .env existente se houver
if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    echo "✅ Arquivo .env encontrado\n";
} else {
    echo "⚠️ Arquivo .env não encontrado, criando...\n";
}

// Adicionar ou atualizar variáveis
foreach ($localConfig as $key => $value) {
    $pattern = "/^$key=.*$/m";
    $replacement = "$key=$value";
    
    if (preg_match($pattern, $envContent)) {
        $envContent = preg_replace($pattern, $replacement, $envContent);
        echo "✅ Atualizada variável $key\n";
    } else {
        $envContent .= "\n$key=$value";
        echo "✅ Adicionada variável $key\n";
    }
}

// Salvar arquivo .env
file_put_contents($envFile, $envContent);
echo "\n✅ Arquivo .env salvo com sucesso\n";

// Criar arquivo de configuração local
echo "\n🔧 Criando arquivo de configuração local...\n";

$localConfigContent = '<?php
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
?>';

file_put_contents(__DIR__ . '/config_local.php', $localConfigContent);
echo "✅ Arquivo config_local.php criado\n";

// Modificar conexao.php para usar configuração local
echo "\n🔧 Modificando conexao.php para usar configuração local...\n";

$conexaoPath = __DIR__ . '/public/conexao.php';
$conexaoContent = file_get_contents($conexaoPath);

// Adicionar verificação para ambiente local no início do arquivo
$localCheck = '<?php
// Verificar se estamos em ambiente local
if (!getenv("DATABASE_URL") || getenv("DATABASE_URL") === "") {
    // Usar configuração local
    require_once __DIR__ . "/../config_local.php";
    $pdo = getLocalDbConnection();
    $GLOBALS["pdo"] = $pdo;
    return;
}

';

// Inserir verificação local no início do arquivo
$conexaoContent = $localCheck . $conexaoContent;
file_put_contents($conexaoPath, $conexaoContent);
echo "✅ conexao.php modificado para suporte local\n";

echo "\n🎉 Configuração local concluída!\n";
echo "\n📋 Próximos passos:\n";
echo "1. Pare o servidor atual: pkill -f 'php -S localhost:8000'\n";
echo "2. Reinicie o servidor: php -S localhost:8000 -t public\n";
echo "3. Acesse: http://localhost:8000/login.php\n";
echo "4. Use as credenciais que você configurou\n";

echo "\n💡 Se ainda houver problemas:\n";
echo "- Verifique se o banco painel_smile existe\n";
echo "- Execute: php fix_db_correct.php\n";
echo "- Verifique se o PostgreSQL está rodando\n";
?>
