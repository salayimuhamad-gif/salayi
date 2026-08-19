# Bird WhatsApp OTP — operator setup

WhatsApp account verification sends a six-digit one-time code through
[Bird](https://bird.com)'s **Channels API**. This document lists exactly what
must exist in the Bird workspace, which value of ours maps to which Bird
object, and what remains to be proven with one real test send before the
feature is enabled in production.

The feature is **off by default**: until `BIRD_API_KEY`,
`BIRD_WORKSPACE_ID`, `BIRD_WHATSAPP_CHANNEL_ID` and
`BIRD_OTP_TEMPLATE_PROJECT_ID` are all set, the verification-choice page
offers Telegram alone and nothing here is ever called.

## 1. What the official Bird contract says (source: Bird Channels API docs)

The pieces below come from Bird's official Channels API documentation for
sending template messages — **not** from the TypeScript SDK, whose
`slug`/`components` convenience surface does not map 1:1 onto the raw
endpoint. `BirdWhatsAppClient` implements the raw contract:

- **Endpoint** — one POST per message:

  ```
  POST https://api.bird.com/workspaces/{workspaceId}/channels/{channelId}/messages
  ```

- **Authentication** — a workspace access key in the header:

  ```
  Authorization: AccessKey <key>
  ```

- **Receiver** — a WhatsApp contact is addressed by its E.164 number as
  `identifierValue`:

  ```json
  "receiver": { "contacts": [ { "identifierValue": "+9647501234567" } ] }
  ```

- **Template reference** — the approved template is referenced by its
  **template-project id** (a UUID), a **version** (`latest` or a version
  UUID) and a published **locale**; variables travel as typed
  `{type, key, value}` parameters:

  ```json
  "template": {
    "projectId":  "00000000-0000-0000-0000-000000000000",
    "version":    "latest",
    "locale":     "en",
    "parameters": [ { "type": "string", "key": "code", "value": "123456" } ]
  }
  ```

- **Success** — the documented answer is **HTTP 202 Accepted**: the message
  is enqueued and its delivery lifecycle is reported out of band. The client
  treats 202 and only 202 as sent; any other status (including an
  undocumented 2xx) makes the platform revoke the just-minted code so no
  live code exists that nobody received.

## 2. What the owner must create in Bird, and which env var each feeds

| Bird object to obtain/create | Where in Bird | Our env var |
| --- | --- | --- |
| A **workspace** | Organisation → workspace; its id (UUID) is in the workspace settings/URL | `BIRD_WORKSPACE_ID` |
| A connected **WhatsApp channel** (a WhatsApp Business sender attached to the workspace) | Channels → WhatsApp; the channel id is a UUID | `BIRD_WHATSAPP_CHANNEL_ID` |
| An **access key** whose role permits sending messages on that channel (Channels API message create) | Settings → Access keys / API access | `BIRD_API_KEY` |
| An **approved WhatsApp message template** for the verification code — WhatsApp requires Meta approval before a business-initiated template can be delivered | Content/Templates (a "template project") | — |
| That template project's **id** (UUID) | The template project's settings/URL | `BIRD_OTP_TEMPLATE_PROJECT_ID` |
| The template **version** to render | `latest`, or a specific published version UUID | `BIRD_OTP_TEMPLATE_VERSION` (default `latest`) |
| The template's published **locale** | One of the locales the template was approved in (e.g. `en`, `ar`) | `BIRD_OTP_TEMPLATE_LOCALE` (default `en`) |
| The template **variable key** that carries the digits — the template body must contain exactly one string variable for the code | Defined in the template body | `BIRD_OTP_TEMPLATE_PARAMETER_KEY` (default `code`) |

`BIRD_BASE_URL` exists only for completeness (regional endpoints, testing);
the default `https://api.bird.com` is the documented host.

## 3. What is configurable on our side

Everything in the table above is read through `config/services.php`
(`services.bird.*`) and nothing is hard-coded: a workspace difference — a
different template variable key, a pinned version, another locale — is a
config change, not a code change. The payload assembly lives in exactly one
place, `app/Modules/Identity/Services/BirdWhatsAppClient.php`, and the
outgoing JSON, headers, URL and 202 handling are pinned byte-for-byte by
`tests/Feature/WhatsAppVerificationTest.php` (against `Http::fake` — CI sends
zero real messages and never holds a real key).

## 4. What still requires one real test send before production enablement

The shape above follows the official documentation, but three values are
facts about the owner's Bird workspace that only a real send can confirm:

1. the **template-project id / version / locale** triplet resolves to the
   approved template (a typo or an unpublished locale is refused by Bird at
   send time, not by anything we can test offline);
2. the **variable key** matches the template body (a mismatched key renders
   a template with an empty code);
3. the **access key's role** actually permits message creation on that
   channel.

So before enabling in production: configure a test environment with the real
values, trigger one send to an owned number from the WhatsApp verification
page, and confirm (a) Bird answers 202, (b) the WhatsApp message arrives with
the six digits rendered, and (c) typing the code back verifies the account.
Until that single send has been observed, keep the `BIRD_*` values unset in
production — the product then behaves exactly as it did before this feature
shipped.
