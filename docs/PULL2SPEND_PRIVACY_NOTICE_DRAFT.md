# Pull2Spend - Privacy Notice (Draft)

| Document control | Value |
| --- | --- |
| Version | 0.1 |
| Date | 28 October 2026 |
| Status | Draft for business and legal review; not approved or effective |
| Draft notice identifier | `P2S-PRIVACY-2026-10-28-DRAFT-01` |
| Effective date | To be assigned after approval |
| Design basis | [FDD v0.8](PULL2SPEND_FDD.md) and [implementation plan v0.12](PULL2SPEND_PHASED_IMPLEMENTATION_PLAN.md) |
| Related terms | [Consumer Terms draft](PULL2SPEND_TERMS_DRAFT.md) |
| Review copy | [Privacy Notice PDF](PULL2SPEND_PRIVACY_NOTICE_DRAFT.pdf) |

**Review status.** This draft describes the intended service and proposed privacy commitments, not a claim that every feature or control is operational. Sections 1-12 are proposed customer wording. Section 13 is an internal review appendix and must be removed from the published customer copy. Complete all bracketed fields and validate the actual data flows before publication.

The current development work uses synthetic identity data. Licensed KYC/screening integration is deferred, real capture is unavailable and financial admission remains disabled. Hosting, processing-location selection and evidence-store provisioning remain outside the current work. This draft does not authorize real-data collection.

## 1. Who is responsible for your data

**[LEGAL OPERATOR NAME]**, at **[REGISTERED ADDRESS]**, is responsible for the Pull2Spend processing described here, in the role of **[CONFIRMED CONTROLLER ROLE]**. Contact our privacy team at **[PRIVACY CONTACT]** and our support team at **[SUPPORT CHANNEL]**.

Loyalty Partners and wallet/card providers may also process data for their own responsibilities. Before you connect or activate those services, we will identify the relevant parties, explain their roles and provide their privacy information.

## 2. What we collect and where it comes from

We collect information you provide, information generated when you use the service, and information received from the partners or verification sources involved in an enabled feature.

| Data category | Examples and source |
| --- | --- |
| Registration and contact | Name, email/contact details, contact verification records and account identifiers you provide. Authentication records include protected password information, not a readable password. |
| Terms, permissions and preferences | Accepted Terms/Privacy Notice versions and time, connection permissions, separate consent records where required, and communication choices. |
| Device and security | App/device information, IP/network and session records, public device keys, signed authentication proofs, security events and relevant fraud indicators. |
| Identity verification, when enabled | Permitted ID-document details and images, document portrait, live-selfie/liveness capture, face-comparison results and associated verification data. The approved capture notice will identify the actual biometric media/templates processed. |
| Screening and review | Sanctions, politically exposed person/related-party and adverse-media search results, source references/timestamps, matches, explanations, risk assessments and reviewer decisions. Sources include authorized screening providers and relevant public/official records. |
| Loyalty connections and offers | Partner account references, connection/access tokens, points quantity, partner-supplied monetary value and currency, quote/transaction references, validity information and confirmations. |
| Financial activity, when enabled | Wallet entries, fees, card/merchant transaction information, holds, captures, refunds, disputes, funding/settlement references and reconciliation records from the relevant providers. |
| Support and operational records | Your case details, messages and supporting material, together with access/audit and incident records needed to investigate and resolve requests. |

We do not ask you to send passwords, contact codes or full card credentials through a support message. The intended design uses an issuer-controlled secure card reveal rather than storing a full PAN or CVV in the Pull2Spend application database; the final notice must match the actual issuer integration.

## 3. Device biometrics and identity-verification biometrics

Device biometric sign-in and remote identity verification are different processes.

For device sign-in, the device's operating system checks your configured biometric and authorizes use of a device-held key. Pull2Spend receives the public key and signed proof; it does not receive your operating-system fingerprint or Face ID template. Password fallback remains available where the device method is unavailable.

Remote identity verification may process a document portrait, live selfie and technical face/liveness results through the separately disclosed verification process. Before capture, we will explain what is processed, who receives it, why it is needed, how long it is kept and any separate permission required. We will not enable real capture until the required provider, permission and raw-media retention arrangements are settled and disclosed.

## 4. Why we use information

We use information to register and secure your account, verify contact and identity, assess eligibility, detect fraud, perform required screening, connect your authorized loyalty accounts, present and execute approved redemptions, operate enabled wallet/card services, keep financial records, resolve cases and meet applicable legal obligations.

The basis for each activity depends on the applicable law and the purpose. Where consent is required, we ask for it for that purpose. Where processing is necessary for a lawful service or legal/compliance obligation, we explain that basis and the information required. We do not treat a general Privacy Notice acknowledgment as blanket consent, or assume every activity depends on consent that can override a legal retention obligation.

Providing required registration, verification or transaction information is necessary for the relevant feature. If you decline it or withdraw a required permission, we may be unable to provide that feature. We will explain available alternatives or review routes where they exist. We use verification information for verification, security and lawful compliance purposes, not for advertising profiling.

## 5. Who receives information

We disclose information needed for the relevant purpose to the identified recipients:

- Loyalty Partners, to verify authorized account access, obtain balances/offers, commit or recover a redemption and resolve discrepancies.
- Identity and screening providers, to perform the disclosed checks and return results for review.
- The wallet/card provider, issuer, processor, payment network and relevant transaction participants, to operate their services and handle financial activity or disputes.
- Contracted communications, support, security and other service providers, under the responsibilities and access controls applicable to their work.
- Our authorized personnel and professional advisers, within their responsibilities and confidentiality requirements.
- Regulators, courts, law-enforcement or other authorized recipients when required or otherwise lawfully justified.

Recipients for enabled services are **[VERIFICATION/SCREENING PROVIDERS]**, **[WALLET/CARD PROVIDERS]** and **[OTHER MATERIAL RECIPIENTS, ROLES AND PRIVACY LINKS]**. We limit access and disclosure to the purpose involved.

We do not sell personal data. Optional marketing use is subject to the separate choices described below.

## 6. Where data is processed

The final notice will identify **[PROCESSING/STORAGE COUNTRIES AND RELEVANT RECIPIENT LOCATIONS]** and describe **[APPLICABLE TRANSFER BASIS AND SAFEGUARDS]** before collection or transfer begins. We will assess cross-border disclosures under the applicable rules and agreements.

A general acknowledgment of this notice does not provide blanket consent to unspecified international transfers.

## 7. How long we keep information

We keep records for the period needed for their stated purpose and applicable legal obligations. Closing an account or withdrawing consent does not automatically erase records that must lawfully be retained. When a justified period ends, we delete or anonymize the information under the approved schedule. A legal hold may extend retention for the affected records.

The current planning baseline, subject to confirmation for the actual operator and legal regime, is:

| Record class | Proposed rule and required confirmation |
| --- | --- |
| Required customer-due-diligence and relationship records | Where the Qatar AML requirements apply, at least 10 years after the relationship ends, with the applicable rule for an occasional transaction also assessed. Confirm the record classes covered. |
| Required transaction/operation records | Where those requirements apply, at least 10 years after transaction/operation completion. Confirm any longer applicable requirement or legal hold. |
| Raw document images, selfies, recordings and biometric templates | Separate necessity/retention assessment required. A 10-year financial-record rule does not automatically make every raw biometric item necessary for 10 years. Final period and deletion trigger: **[APPROVED RAW-EVIDENCE SCHEDULE]**. |
| Pending/abandoned registrations, operational/security logs and support cases | Purpose-specific schedule required: **[APPROVED PERIODS, START EVENTS AND DELETION RULES]**. Do not retain these indefinitely by default. |

Before collecting the relevant data, we will disclose the applicable period or sufficiently clear retention criteria for each remaining record class.

## 8. Automated checks, review and restrictions

Automated document, face/liveness and screening checks support the verification process. A successful automated check alone does not approve financial access. The intended approval process requires an examiner recommendation and a different authorized checker. An unresolved match, inconclusive result or unavailable source does not mean you are cleared.

We may restrict new actions to protect the account or comply with applicable obligations. You may request correction or human review through **[REVIEW CHANNEL]**. We will explain what further information is needed where permitted, while protecting information we cannot lawfully disclose. We will disclose the effects and review route of any additional automated decision before using it for your account.

## 9. Your choices and rights

Subject to the applicable law and lawful exceptions, you may request information about our processing, access or a copy of your data, correction of inaccurate information, objection to processing, deletion where justified, or withdrawal of consent for activities that rely on it. You may also raise a privacy complaint.

Send requests to **[PRIVACY CONTACT/IN-APP RIGHTS ROUTE]**. We will use proportionate checks to verify your identity, explain the outcome and any lawful reason we cannot fully comply, and respond within the applicable period. We will not promise to delete records that we are legally required to retain. If the outcome is unsatisfactory, you may use **[APPLICABLE PRIVACY AUTHORITY AND COMPLAINT ROUTE]**.

You can also disconnect a Loyalty Partner, revoke an enrolled device or change optional notification/marketing choices through the available controls or support. These actions affect the relevant future access or communication; they do not undo completed transactions or lawful recordkeeping.

## 10. Communications and optional marketing

We send necessary account, security, verification, transaction and case communications through the permitted service channels. Optional promotions require a separate, clear choice where required by law. Marketing controls must not be preselected or bundled into acceptance of these Terms/Privacy Notice.

You may opt out through **[PREFERENCE/UNSUBSCRIBE ROUTE]**. Opting out of promotions does not cancel a transaction, close your account or remove necessary service communications. We will identify the actual channels used and preserve your channel-specific choices.

## 11. Protection, incidents and notice changes

The intended controls include protected authentication credentials, private encrypted evidence, scoped access, audited review and separation of sensitive data from general logs/exports. We assess providers and access according to their roles. No system can eliminate every risk. We handle incidents and provide required notifications under the applicable rules.

We will publish material changes to this notice with a new version and date, explain changes to processing, and seek fresh consent where required. We will not use a changed notice to silently broaden a permission that requires your separate agreement. You can access the current notice and request the version relevant to your records through **[NOTICE HISTORY/CONTACT ROUTE]**.

## 12. Privacy acknowledgment and separate choices

Proposed registration acknowledgment: **"I acknowledge that I have read the Pull2Spend Privacy Notice."** It may accompany the Terms acceptance wording, but does not replace separate marketing, partner-connection or identity/biometric consent when required.

The app presents the full notice before acknowledgment, and we record its version and the time. Before real capture, a capture-specific notice and any required separate consent will identify the providers, purposes and retention arrangements involved.

## 13. Internal review appendix - remove before publication

| Required decision | Reviewer and evidence required |
| --- | --- |
| Legal operator/controller and contact routes | Business and legal; complete the identity, address, controller-role and privacy/support/complaints fields. Appoint the responsible privacy contact. |
| Applicable regime and processing basis | Legal/compliance; confirm Qatar mainland, QFC or another regime, document the basis for each purpose, special-nature/biometric requirements and any required permission. Do not import a foreign-law basis by assumption. |
| Actual data and recipient map | Product, engineering and privacy; verify what each app/SDK, issuer, partner and supplier collects, receives, stores and deletes. Regula and ComplyAdvantage Mesh are approved design choices whose licensed integration is deferred, not evidence of current processing. |
| Raw evidence, screening and children/cohort | Privacy/compliance; assess sensitive and criminal-offence-related material, biometric media/templates, supported age/cohort and any applicable permits or impact assessment before collection. |
| Retention and locations | Legal/compliance, issuer and operations; approve each record class and deletion trigger, recipient countries and transfer safeguards. Hosting and evidence-store provisioning remain deferred under H-01. |
| Rights, review and incident handling | Privacy, compliance and operations; validate reachable request channels, identity checks, applicable deadlines, escalation/authority routes and review procedures. |
| Optional marketing and data-use commitments | Business/privacy; confirm the no-sale and no-advertising-use-of-verification-data commitments, channel-specific opt-ins/opt-outs and supplier contract restrictions. |
| Approval, translations and publication | Named business and legal/privacy approvers; record the approved text, date, accurate English/Arabic wording and final version identifier. No approval is inferred from this drafting request. |

The draft identifier is for review only and must not be configured as an approved notice. Backend integration uses `PULL2SPEND_PRIVACY_NOTICE_VERSION`; Flutter uses `PULL2SPEND_PRIVACY_VERSION` and `PULL2SPEND_PRIVACY_TEXT`. Keep those fields aligned to the eventual approved publication, not this draft. No environment values, application admission controls or hosting arrangements are changed here.

Research references checked 28 October 2026: [Qatar Law No. 13 of 2016, published English text](https://www.almeezan.qa/EnglishLaws/132016.pdf), [NCSA guidance hub, including privacy notice and individual-rights guidance](https://ncsa.gov.qa/en/pages/guidance-hub-for-regulated-entities), and [Qatar AML Law No. 20 of 2019, Article 20](https://almeezan.qa/EnglishLaws/Law%20No.%20%2820%29%20of%202019%20on%20the%20Promulgation%20of%20Anti-Money%20Laundering%20and%20Terrorism%20Financing%20Law.pdf). Source retrieval included indexed official extracts; some direct PDF endpoints redirected or timed out. Legal review must confirm the current authoritative texts, applicable regime and publication requirements. These references do not certify compliance.
