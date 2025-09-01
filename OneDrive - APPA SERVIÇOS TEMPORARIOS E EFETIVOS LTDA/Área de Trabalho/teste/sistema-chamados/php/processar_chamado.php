<?php
/**
 * Script para processar abertura de chamados
 * Sistema de Chamados - Inspirado no Milvus
 */

require_once 'config.php';

// Configurar headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Tratar requisições OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    enviarResposta(false, 'Método não permitido. Use POST.');
}

try {
    // Conectar ao banco de dados
    $pdo = conectarBanco();
    if (!$pdo) {
        enviarResposta(false, 'Erro de conexão com o banco de dados.');
    }
    
    // Validar e sanitizar dados de entrada
    $dados = validarDadosEntrada();
    
    // Processar usuário (criar ou buscar existente)
    $usuario_id = processarUsuario($pdo, $dados);
    
    // Criar chamado
    $chamado_id = criarChamado($pdo, $dados, $usuario_id);
    
    // Processar anexos se existirem
    $anexos_processados = processarAnexos($pdo, $chamado_id);
    
    // Buscar dados do chamado criado
    $chamado = buscarChamado($pdo, $chamado_id);
    
    // Registrar log
    logarAtividade('CHAMADO_CRIADO', "Chamado {$chamado['numero_chamado']} criado", $usuario_id);
    
    // Enviar notificação por e-mail (opcional)
    // enviarNotificacaoEmail($chamado, $dados);
    
    // Resposta de sucesso
    enviarResposta(true, 'Chamado criado com sucesso!', [
        'numeroChamado' => $chamado['numero_chamado'],
        'id' => $chamado_id,
        'anexos' => $anexos_processados
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao processar chamado: " . $e->getMessage());
    enviarResposta(false, 'Erro interno do servidor. Tente novamente.');
}

/**
 * Validar e sanitizar dados de entrada
 */
function validarDadosEntrada() {
    $erros = [];
    $dados = [];
    
    // Campos obrigatórios
    $camposObrigatorios = ['nome', 'email', 'assunto', 'categoria', 'prioridade', 'descricao'];
    
    foreach ($camposObrigatorios as $campo) {
        if (empty($_POST[$campo])) {
            $erros[] = "Campo '{$campo}' é obrigatório.";
        } else {
            $dados[$campo] = sanitizar($_POST[$campo]);
        }
    }
    
    // Validações específicas
    if (!empty($dados['email']) && !validarEmail($dados['email'])) {
        $erros[] = "E-mail inválido.";
    }
    
    if (!empty($dados['assunto']) && strlen($dados['assunto']) < 5) {
        $erros[] = "Assunto deve ter pelo menos 5 caracteres.";
    }
    
    if (!empty($dados['descricao']) && strlen($dados['descricao']) < 10) {
        $erros[] = "Descrição deve ter pelo menos 10 caracteres.";
    }
    
    // Campos opcionais
    $camposOpcionais = [
        'telefone', 'empresa', 'cpf', 'cnpj', 'urgencia', 'terminal', 
        'localizacao', 'data_ocorrencia', 'hora_ocorrencia', 'url_relacionada', 
        'impacto', 'observacoes'
    ];
    
    foreach ($camposOpcionais as $campo) {
        $dados[$campo] = isset($_POST[$campo]) ? sanitizar($_POST[$campo]) : null;
    }
    
    // Validar prioridade e urgência
    $prioridadesValidas = ['baixa', 'media', 'alta', 'critica'];
    if (!empty($dados['prioridade']) && !in_array($dados['prioridade'], $prioridadesValidas)) {
        $erros[] = "Prioridade inválida.";
    }
    
    $urgenciasValidas = ['baixa', 'media', 'alta'];
    if (!empty($dados['urgencia']) && !in_array($dados['urgencia'], $urgenciasValidas)) {
        $erros[] = "Urgência inválida.";
    }
    
    $impactosValidos = ['baixo', 'medio', 'alto', 'critico'];
    if (!empty($dados['impacto']) && !in_array($dados['impacto'], $impactosValidos)) {
        $erros[] = "Impacto inválido.";
    }
    
    // Validar categoria
    if (!empty($dados['categoria'])) {
        $categoriasValidas = ['hardware', 'software', 'rede', 'email', 'impressora', 'acesso', 'backup', 'outros'];
        if (!in_array($dados['categoria'], $categoriasValidas)) {
            $erros[] = "Categoria inválida.";
        }
    }
    
    if (!empty($erros)) {
        enviarResposta(false, 'Dados inválidos: ' . implode(', ', $erros));
    }
    
    return $dados;
}

/**
 * Processar usuário (criar ou buscar existente)
 */
function processarUsuario($pdo, $dados) {
    try {
        // Verificar se usuário já existe pelo e-mail
        $sql = "SELECT id FROM usuarios WHERE email = :email";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':email' => $dados['email']]);
        $usuario = $stmt->fetch();
        
        if ($usuario) {
            // Atualizar dados do usuário existente
            $sql = "UPDATE usuarios SET 
                    nome = :nome, 
                    telefone = :telefone, 
                    empresa = :empresa, 
                    cpf = :cpf, 
                    cnpj = :cnpj,
                    data_atualizacao = NOW()
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':nome' => $dados['nome'],
                ':telefone' => $dados['telefone'],
                ':empresa' => $dados['empresa'],
                ':cpf' => $dados['cpf'],
                ':cnpj' => $dados['cnpj'],
                ':id' => $usuario['id']
            ]);
            
            return $usuario['id'];
        } else {
            // Criar novo usuário
            $sql = "INSERT INTO usuarios (nome, email, telefone, empresa, cpf, cnpj) 
                    VALUES (:nome, :email, :telefone, :empresa, :cpf, :cnpj)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':nome' => $dados['nome'],
                ':email' => $dados['email'],
                ':telefone' => $dados['telefone'],
                ':empresa' => $dados['empresa'],
                ':cpf' => $dados['cpf'],
                ':cnpj' => $dados['cnpj']
            ]);
            
            return $pdo->lastInsertId();
        }
    } catch (Exception $e) {
        error_log("Erro ao processar usuário: " . $e->getMessage());
        throw new Exception("Erro ao processar dados do usuário.");
    }
}

/**
 * Criar chamado
 */
function criarChamado($pdo, $dados, $usuario_id) {
    try {
        // Buscar ID da categoria
        $mapeamentoCategoria = [
            'hardware' => 1,
            'software' => 2,
            'rede' => 3,
            'email' => 4,
            'impressora' => 5,
            'acesso' => 6,
            'backup' => 7,
            'outros' => 8
        ];
        
        $categoria_id = $mapeamentoCategoria[$dados['categoria']] ?? 8;
        
        // Gerar número único do chamado
        do {
            $numeroChamado = gerarNumeroChamado();
            $sql = "SELECT id FROM chamados WHERE numero_chamado = :numero";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':numero' => $numeroChamado]);
        } while ($stmt->fetch());
        
        // Inserir chamado
        $sql = "INSERT INTO chamados (
                    numero_chamado, usuario_id, categoria_id, assunto, descricao,
                    prioridade, urgencia, impacto, terminal, localizacao,
                    data_ocorrencia, hora_ocorrencia, url_relacionada, observacoes
                ) VALUES (
                    :numero_chamado, :usuario_id, :categoria_id, :assunto, :descricao,
                    :prioridade, :urgencia, :impacto, :terminal, :localizacao,
                    :data_ocorrencia, :hora_ocorrencia, :url_relacionada, :observacoes
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':numero_chamado' => $numeroChamado,
            ':usuario_id' => $usuario_id,
            ':categoria_id' => $categoria_id,
            ':assunto' => $dados['assunto'],
            ':descricao' => $dados['descricao'],
            ':prioridade' => $dados['prioridade'],
            ':urgencia' => $dados['urgencia'],
            ':impacto' => $dados['impacto'],
            ':terminal' => $dados['terminal'],
            ':localizacao' => $dados['localizacao'],
            ':data_ocorrencia' => $dados['data_ocorrencia'] ?: null,
            ':hora_ocorrencia' => $dados['hora_ocorrencia'] ?: null,
            ':url_relacionada' => $dados['url_relacionada'],
            ':observacoes' => $dados['observacoes']
        ]);
        
        $chamado_id = $pdo->lastInsertId();
        
        // Criar entrada no histórico
        $sql = "INSERT INTO historico_chamados (chamado_id, tipo, titulo, descricao) 
                VALUES (:chamado_id, 'sistema', 'Chamado criado', 'Chamado criado pelo sistema')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':chamado_id' => $chamado_id]);
        
        return $chamado_id;
        
    } catch (Exception $e) {
        error_log("Erro ao criar chamado: " . $e->getMessage());
        throw new Exception("Erro ao criar chamado.");
    }
}

/**
 * Processar anexos
 */
function processarAnexos($pdo, $chamado_id) {
    $anexos_processados = [];
    
    if (!isset($_FILES['anexos']) || empty($_FILES['anexos']['name'][0])) {
        return $anexos_processados;
    }
    
    try {
        // Criar diretório de upload
        $uploadDir = criarDiretorioUpload();
        
        $arquivos = $_FILES['anexos'];
        $totalArquivos = count($arquivos['name']);
        
        for ($i = 0; $i < $totalArquivos; $i++) {
            if ($arquivos['error'][$i] === UPLOAD_ERR_OK) {
                $arquivo = [
                    'name' => $arquivos['name'][$i],
                    'type' => $arquivos['type'][$i],
                    'tmp_name' => $arquivos['tmp_name'][$i],
                    'error' => $arquivos['error'][$i],
                    'size' => $arquivos['size'][$i]
                ];
                
                // Validar arquivo
                $erros = validarUpload($arquivo);
                if (!empty($erros)) {
                    continue; // Pular arquivo inválido
                }
                
                // Gerar nome único para o arquivo
                $extensao = pathinfo($arquivo['name'], PATHINFO_EXTENSION);
                $nomeArquivo = uniqid('anexo_' . $chamado_id . '_') . '.' . $extensao;
                $caminhoCompleto = $uploadDir . $nomeArquivo;
                
                // Mover arquivo
                if (move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
                    // Salvar no banco
                    $sql = "INSERT INTO anexos (chamado_id, nome_original, nome_arquivo, tipo_arquivo, tamanho_arquivo, caminho_arquivo)
                            VALUES (:chamado_id, :nome_original, :nome_arquivo, :tipo_arquivo, :tamanho_arquivo, :caminho_arquivo)";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':chamado_id' => $chamado_id,
                        ':nome_original' => $arquivo['name'],
                        ':nome_arquivo' => $nomeArquivo,
                        ':tipo_arquivo' => $arquivo['type'],
                        ':tamanho_arquivo' => $arquivo['size'],
                        ':caminho_arquivo' => $caminhoCompleto
                    ]);
                    
                    $anexos_processados[] = [
                        'nome_original' => $arquivo['name'],
                        'tamanho' => $arquivo['size']
                    ];
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Erro ao processar anexos: " . $e->getMessage());
        // Não falhar o processo por causa dos anexos
    }
    
    return $anexos_processados;
}

/**
 * Buscar dados do chamado criado
 */
function buscarChamado($pdo, $chamado_id) {
    try {
        $sql = "SELECT numero_chamado, assunto, data_criacao FROM chamados WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $chamado_id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Erro ao buscar chamado: " . $e->getMessage());
        return ['numero_chamado' => 'ERRO', 'assunto' => '', 'data_criacao' => date('Y-m-d H:i:s')];
    }
}

/**
 * Enviar notificação por e-mail (implementação futura)
 */
function enviarNotificacaoEmail($chamado, $dados) {
    // Implementar envio de e-mail usando PHPMailer ou similar
    // Por enquanto, apenas log
    error_log("Notificação de e-mail seria enviada para: " . $dados['email']);
}
?>

