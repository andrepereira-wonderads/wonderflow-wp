# Guia de instalação no site de uma clínica

Para a equipa da WonderAds. Demora cerca de 15 minutos por site.

## 1. No CRM (Wonderflow)

1. Entrar como dono ou gestor da clínica.
2. **Definições -> Integrações -> Nova chave**, tipo **Servidor**, nome "Site WordPress".
3. Copiar a chave `whk_...` (só aparece uma vez).
4. Se a clínica vai usar o WhatsApp: confirmar que o número está ligado no painel da
   plataforma (separador WhatsApp).

## 2. No WordPress

1. **Plugins -> Adicionar novo -> Carregar plugin**: o zip `wonderflow-vX.Y.Z.zip` da
   página de releases do repositório. Ativar.
2. **Definições -> Wonderflow**:
   - URL do endpoint: `https://<projeto>.supabase.co/functions/v1/ingest`
   - Chave de servidor: colar a `whk_...`
   - País por omissão: `PT`
   - Versão do texto de consentimento: por exemplo `site-2026-11` (mudar quando o texto
     dos formulários mudar)
3. **Guardar** e depois **Testar ligação**: tem de aparecer "Ligado".
4. Ligar **Enviar formulários** e/ou **Seguir cliques** no WhatsApp, e guardar.

## 3. Formulários

- O plugin deteta sozinho nome, telemóvel, email e as caixas de consentimento pelo tipo
  e pelo nome do campo (por exemplo "Nome", "Telemóvel", "Aceito a política de
  privacidade", "Quero receber lembretes por WhatsApp", "Quero receber novidades").
- Se um formulário tiver nomes estranhos, usar o **Mapeamento de campos** (JSON). O id do
  formulário vê-se no construtor (Contact Form 7: o número no shortcode; Elementor: o
  "Nome do formulário" do widget).
- Para não enviar um formulário (por exemplo, candidaturas a emprego):
  `{"cf7:456": false}`.
- A mensagem livre nunca é enviada.
- Os consentimentos de lembretes (`utility`) e de marketing são caixas separadas e não
  podem vir pré-marcadas.

## 4. WhatsApp

- Número da clínica no formato internacional, só algarismos: `351912345678`.
- Os botões que já existem (Joinchat, Click to Chat, WP Social Chat, botão do Elementor,
  links `wa.me`) passam a levar o código Ref. Para um botão novo, usar o shortcode
  `[wonderflow_whatsapp label="Marcar pelo WhatsApp"]`.

## 5. Testar

1. Abrir o site numa janela anónima com
   `?utm_source=teste&utm_medium=cpc&utm_campaign=instalacao`.
2. Aceitar os cookies de análise, se houver banner.
3. Enviar um formulário com dados fictícios (por exemplo "Teste Wonderflow",
   `912000777`).
4. Em **Definições -> Wonderflow -> Últimos envios** deve aparecer "Enviado" com o código
   `http_201`. No CRM, o contacto aparece com o canal "Pesquisa paga" e o meio
   "Formulário do site".
5. Clicar no botão de WhatsApp: a mensagem abre com "Ref XXXXX" e aparece um "Clique no
   WhatsApp" enviado no registo. Ao enviar essa mensagem, o CRM liga a conversa ao clique.
6. Apagar o contacto de teste no CRM.

## Problemas comuns

| No registo | Causa | O que fazer |
|---|---|---|
| `invalid_key` | Chave revogada ou errada | Criar uma nova no CRM e colar |
| `refused` | O plano da clínica não inclui formulários / WhatsApp | Rever o plano no painel da plataforma |
| `invalid_phone` | Telemóvel inválido no formulário | Nada: não se repete |
| `rate_limited`, `network_error`, `http_5xx` | Temporário | Repete sozinho (até 8 tentativas) |
| Nada aparece | Formulário sem nome, telemóvel nem email detetados, ou WP-Cron desligado | Ver o mapeamento; confirmar que o WP-Cron corre (ou um cron do servidor a chamar `wp-cron.php`) |
