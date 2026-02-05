# n8n Execution Explorer

Uma interface simples e eficiente desenvolvida em PHP para visualizar, filtrar e inspecionar o histórico de execuções do [n8n](https://n8n.io/).

## 🚀 Funcionalidades

- **Listagem Dinâmica**: Carregamento infinito (Infinite Scroll) para navegar por milhares de execuções sem recarregar a página.
- **Filtros Avançados**: Busque por Workflow, Status (Sucesso, Erro, Running) e Intervalo de Datas.
- **Visualização de Detalhes**:
  - **Timeline de Nodes**: Veja o fluxo exato da execução, nó a nó, em uma interface de abas intuitiva.
  - **Inspeção de JSON**: Analise os dados de entrada e saída de cada nó com formatação visual colorida e hierárquica.
  - **Raw Data**: Acesso ao JSON bruto completo da execução para debugging profundo.
- **UX Otimizada**:
  - Tempos convertidos automaticamente para o fuso horário local (ex: Brasília UTC-3).
  - Indicação visual clara de status (verde/vermelho).
  - Interface responsiva e moderna com Tailwind CSS e Alpine.js.

## 📋 Pré-requisitos

- Um servidor web com suporte a **PHP** (ex: Apache, Nginx, Laragon, XAMPP).
- Extensão `curl` do PHP habilitada.
- Acesso a uma instância do n8n com a API habilitada.

## ⚙️ Configuração

1. **Clone ou baixe** este repositório para a pasta pública do seu servidor web (ex: `www` ou `htdocs`).
2. Abra o arquivo `index.php` em um editor de texto.
3. Localize a seção de **CONFIGURAÇÕES** no topo do arquivo (linhas 5-6):

```php
$n8nUrl = 'https://sua-instancia-n8n.com'; // URL base do seu n8n (sem barra no final)
$apiKey = 'sua-api-key-aqui';              // Sua chave de API do n8n
```

1. Substitua os valores pelas credenciais da sua instância.
   - Para gerar uma API Key no n8n, vá em: `Settings` > `Public API` > `API Keys` > `Create API Key`.

## 🖥️ Como Usar

1. Acesse o script pelo navegador (ex: `http://localhost/execucoesN8nIvb`).
2. A página carregará automaticamente as últimas execuções de todos os workflows.
3. **Para filtrar**:
   - Selecione um Workflow específico no dropdown.
   - Escolha o status (All, Success, Error).
   - Defina as datas de início e fim.
   - Clique em "Filtrar".
4. **Para ver detalhes**:
   - Clique no botão **"JSON"** (ícone de código) na coluna de ações de qualquer execução.
   - Um modal abrirá com a timeline dos nodes. Navegue pelas abas para ver o que aconteceu em cada etapa.

## 🛠️ Tecnologias Utilizadas

- **Frontend**: HTML5, Tailwind CSS (via CDN), Alpine.js (via CDN).
- **Backend**: PHP (CURL para comunicação com a API do n8n).
- **Bibliotecas**: Moment.js (manipulação de datas).

---

**Nota**: Este é um projeto independente e não oficial do n8n.
