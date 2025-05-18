# Documentação: Lógica de Vagas em Seminários

## Valores e Significados

No sistema de seminários, os limites de vagas são gerenciados da seguinte maneira:

### Para `total_vagas` (limite total de vagas):

- **-1**: Representa **Vagas Ilimitadas**. Este é o único valor que deve ser interpretado como "sem limite de vagas".
- **0**: Representa o valor padrão do Moodle para "ilimitado", mas em nosso sistema deve ser tratado como valor normal (0 vagas).
- **> 0**: Representa o número exato de vagas disponíveis para o seminário.

### Para `vagas_disponiveis` (vagas ainda disponíveis):

- **-1**: Representa **Vagas Ilimitadas**. Este é o único valor que deve ser interpretado como "sem limite de vagas".
- **0**: Não há mais vagas disponíveis (seminário lotado).
- **> 0**: Indica a quantidade exata de vagas ainda disponíveis.

## Como verificar se um seminário tem vagas ilimitadas:

```php
// Forma correta de verificar se um seminário tem vagas ilimitadas
if ($sem['total_vagas'] === -1 || $sem['vagas_disponiveis'] === -1) {
    // Seminário tem vagas ilimitadas
}
```

## Como verificar se um seminário tem vagas disponíveis:

```php
// Forma correta de verificar se um seminário tem vagas disponíveis
if (($sem['vagas_disponiveis'] > 0) || ($sem['vagas_disponiveis'] === -1)) {
    // Seminário tem vagas disponíveis (ou tem vagas ilimitadas)
}
```

## Integrações com o Moodle

O Moodle usa o campo `customint3` nos métodos de inscrição para definir o limite de vagas. Quando o valor é `0`, o Moodle interpreta como "sem limite", mas em nosso sistema convertemos para `-1` para maior clareza.

## Recomendações

1. Sempre use comparações estritas (`===` e `!==`) para verificar valores ilimitados.
2. Evite usar comparações como `<= 0` ou `< 0` para verificar vagas ilimitadas, pois isso pode causar comportamentos inesperados.
3. Lembre-se que apenas o valor `-1` indica vagas ilimitadas em nosso sistema.

## Histórico

Esta convenção foi estabelecida para corrigir problemas na exibição de limites de vagas, onde valores como `0` ou negativos diferentes de `-1` estavam sendo incorretamente interpretados como "ilimitado". 