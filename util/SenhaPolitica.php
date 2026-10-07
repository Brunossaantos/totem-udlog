<?php

namespace Util;

/**
 * Politica e hash de senha da Gestao Totem (demanda gestao-totem, F1).
 *
 * Politica (decisao do plano): minimo 12 caracteres, maximo 72 BYTES (limite
 * real do bcrypt, usado como teto unico para qualquer algoritmo), diferente do
 * login, fora de uma lista curta de senhas comuns, nao so de espacos, nao um
 * unico caractere repetido e com pelo menos 5 caracteres distintos. SEM regras
 * de composicao (maiuscula, digito, simbolo): comprimento e a regra.
 *
 * Todo parametro que carrega senha ou hash leva #[\SensitiveParameter] (PHP
 * 8.2+ o remove dos stack traces; no 8.0/8.1 o atributo e inerte e a defesa e
 * `zend.exception_ignore_args`, ligado pela gestao).
 *
 * Hash: PASSWORD_ARGON2ID quando o PHP do servidor o oferece (defined()), senao
 * PASSWORD_BCRYPT cost 12. O algoritmo e as opcoes podem mudar com o tempo:
 * `precisaRehash()` + regravacao no login migram o hash sem o usuario perceber.
 */
final class SenhaPolitica
{
    public const MIN_CARACTERES = 12;

    public const MAX_BYTES = 72;

    /** Teto defensivo para a senha DIGITADA no login (evita hash de entrada gigante). */
    public const MAX_BYTES_LOGIN = 1024;

    /** Minimo de caracteres DISTINTOS (recusa "aaaaaaaaaaaa", "abababababab"). */
    public const MIN_DISTINTOS = 5;

    private const ARGON_OPCOES = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];

    private const BCRYPT_OPCOES = ['cost' => 12];

    /**
     * Hashes de uma senha aleatoria descartada (nunca existiu usuario com ela),
     * um por algoritmo e MESMAS opcoes do hash real: `password_verify` contra
     * este hash gasta o mesmo tempo de uma verificacao real, para o login
     * inexistente (ou bloqueado) nao ser distinguivel por tempo.
     */
    private const DUMMY_ARGON2ID = '$argon2id$v=19$m=19456,t=2,p=1$LkphTzFYdmI3QmJYenJCbg$L7jL2+7nCxCCUR3K99G6WZGfj1Imr61b6dB8A9Id8JA';

    private const DUMMY_BCRYPT = '$2y$12$bg7z6YFGtQ.KdC.xrsmCZu6xZrB5CcEnUFFNC89PmI0yaf5mlNTpO';

    /** Senhas comuns com 12+ caracteres (comparacao em minusculas, sem espacos). */
    private const COMUNS = [
        '123456789012', '1234567890123', '12345678901234', '123456789123',
        '111111111111', '000000000000', '123123123123', '121212121212',
        'qwertyuiop12', 'qwertyuiop123', 'qwertyuiopas', 'qwertyuiopasdf', 'asdfghjkl123',
        'asdfghjklzxc', 'zxcvbnm12345', 'password1234', 'password12345', 'passw0rd1234',
        'passwordpassword', 'senha1234567', 'senha12345678', 'senhasenha123', 'senhasegura1',
        'senhasegura12', 'minhasenha123', 'minhasenha1234', 'mudar1234567', 'mudar12345678',
        'administrador', 'administrator', 'administrador1', 'administrador123',
        'udlog1234567', 'udlog12345678', 'udlog@123456', 'udlogudlog123', 'udlog2025udlog',
        'udlog2026udlog', 'totemudlog123', 'gestaototem1', 'gestaototem123', 'bemvindo12345',
        'bemvindo1234', 'trocar123456', 'trocarsenha12', 'abcdefghijkl', 'abcdefgh1234',
        'iloveyou1234', 'letmein12345', 'welcome12345', 'changeme1234', 'changemenow1',
        'aaaaaaaaaaaa', 'abc123abc123', 'brasil123456', 'brasilbrasil1', 'corinthians1',
        'flamengo1234', 'palmeiras123', 'mudarsenha123', 'novasenha1234', 'novasenha123',
        '1q2w3e4r5t6y', '1qaz2wsx3edc', 'qazwsxedcrfv', 'qwertyuiop[]', 'abcd12345678',
        '123456789abc', '1234567890ab', 'admin1234567', 'admin12345678', 'administrador@1',
        'mudar@123456', 'trocar@123456', 'senha@123456', 'senha@1234567', 'password@123',
        'password123!', 'p@ssw0rd1234', 'p@ssword1234', 'bemvindoudlog', 'udlog@2026',
        'udlog20262026', 'udlogtotem123', 'totem@123456', 'totemudlog@1', 'iloveyou12345',
        'letmein123456', 'welcome123456', 'qwerty123456', 'qwerty1234567', 'qwertyuiop1234',
    ];

    public static function algoritmo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string,int> */
    public static function opcoes(): array
    {
        return defined('PASSWORD_ARGON2ID') ? self::ARGON_OPCOES : self::BCRYPT_OPCOES;
    }

    public static function gerarHash(#[\SensitiveParameter] string $senha): string
    {
        $hash = password_hash($senha, self::algoritmo(), self::opcoes());
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Falha ao gerar o hash da senha');
        }

        return $hash;
    }

    public static function verificar(#[\SensitiveParameter] string $senha, #[\SensitiveParameter] string $hash): bool
    {
        return $hash !== '' && password_verify($senha, $hash);
    }

    public static function precisaRehash(#[\SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, self::algoritmo(), self::opcoes());
    }

    /** Verificacao "de mentira" com custo igual ao real (usuario inexistente ou bloqueado). */
    public static function verificarDummy(#[\SensitiveParameter] string $senha): void
    {
        password_verify($senha, defined('PASSWORD_ARGON2ID') ? self::DUMMY_ARGON2ID : self::DUMMY_BCRYPT);
    }

    /**
     * @param string|null $login      login do usuario (a senha nao pode ser igual a ele)
     * @param string|null $senhaAtual senha atual (a nova nao pode ser igual a ela)
     * @return string|null mensagem de erro para o usuario, ou null se a senha e aceita
     */
    public static function validar(#[\SensitiveParameter] string $senha, ?string $login = null, #[\SensitiveParameter] ?string $senhaAtual = null): ?string
    {
        if (strlen($senha) > self::MAX_BYTES) {
            return 'A senha é longa demais: o limite é de ' . self::MAX_BYTES . ' caracteres (acentos contam mais). Use uma senha mais curta.';
        }
        if (!preg_match('//u', $senha)) {
            return 'A senha tem caracteres que o sistema não aceita. Digite-a de novo, sem copiar de outro programa.';
        }
        if (trim($senha) === '') {
            return 'A senha não pode ser só de espaços. Escolha uma senha com letras, números ou símbolos.';
        }
        if (mb_strlen($senha, 'UTF-8') < self::MIN_CARACTERES) {
            return 'A senha é curta demais: use pelo menos ' . self::MIN_CARACTERES . ' caracteres.';
        }
        $distintos = count(array_unique(mb_str_split($senha, 1, 'UTF-8')));
        if ($distintos === 1) {
            return 'A senha repete um único caractere. Escolha uma senha mais variada.';
        }
        if ($distintos < self::MIN_DISTINTOS) {
            return 'A senha usa poucos caracteres diferentes (o mínimo é ' . self::MIN_DISTINTOS . '). Escolha uma senha mais variada.';
        }
        if ($login !== null && $login !== '' && strtolower(trim($senha)) === strtolower($login)) {
            return 'A senha não pode ser igual ao login. Escolha outra.';
        }
        if ($senhaAtual !== null && hash_equals($senhaAtual, $senha)) {
            return 'A nova senha precisa ser diferente da senha atual.';
        }
        $normalizada = mb_strtolower(str_replace(' ', '', $senha), 'UTF-8');
        if (in_array($normalizada, self::COMUNS, true)) {
            return 'Essa senha é muito comum e fácil de adivinhar. Escolha outra.';
        }

        return null;
    }

    /**
     * Senha temporaria gerada no SERVIDOR (CSPRNG): 16 caracteres de um
     * alfabeto sem ambiguidade (sem 0/O/1/l/I), em 4 grupos separados por
     * hifen (19 caracteres, bem abaixo do teto de 72 bytes).
     */
    public static function gerarTemporaria(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alfabeto) - 1;
        $grupos = [];
        for ($g = 0; $g < 4; $g++) {
            $grupo = '';
            for ($i = 0; $i < 4; $i++) {
                $grupo .= $alfabeto[random_int(0, $max)];
            }
            $grupos[] = $grupo;
        }

        return implode('-', $grupos);
    }
}
