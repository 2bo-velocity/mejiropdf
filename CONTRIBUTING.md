# Contributing

**This project does not accept code contributions.** Pull requests are not
merged — they are closed. Bug reports and feature requests, however, are
genuinely welcome; please open an [issue](../../issues).

That split is deliberate, and it is worth explaining rather than leaving you to
discover it after writing a patch.

## Why pull requests are not accepted

MejiroPDF is **dual-licensed**: AGPL-3.0-or-later, or a commercial license for
organisations that cannot take on the AGPL's source-disclosure obligation. That
arrangement only works while one person holds the copyright to all of it.

If someone else's code enters the library — even a few lines — the copyright to
those lines belongs to its author. Selling a commercial license covering that
code would then require permission from every contributor, or a signed copyright
assignment (CLA) from each of them. Keeping the codebase 100% first-party avoids
the problem entirely instead of managing it:

- no CLA to administer, and no risk of shipping code the commercial license
  cannot legally cover;
- licensing terms can be revised without collecting anyone's consent;
- one person remains accountable for the design and its consistency.

This is the same reasoning behind SQLite's and iText's contribution policies. It
is not a judgement about the quality of anyone's patch.

## What is welcome

**Bug reports.** They are the most useful thing you can send, and they are used
to improve the library. Fixes are written by the author from the report, not
copied from submitted code.

A report is easiest to act on when it includes:

- the MejiroPDF version (`MejiroPDF\Version::VERSION`) and your PHP version;
- a **minimal script** that reproduces the problem — ideally one that writes to
  `php://temp` and needs no other setup;
- what you expected, and what happened instead;
- the error code (for example `MP-VAL-1001`) and the exception's `context`
  array, if one was thrown.

**Feature requests and design questions** are also fine as issues. Whether and
when anything is acted on is at the author's discretion.

## Support

**There is no support commitment.** This project is maintained by one person
alongside other work. Issues may go unanswered, and no response time or fix
timeline is promised. Everything here is provided as-is under the terms of the
[license](./LICENSE).

If you need a support commitment, that is what a commercial license is for —
contact <support@2bo-velocity.com>.

## Security issues

**Please do not open a public issue for a security vulnerability.** Email
<support@2bo-velocity.com> with the details and a way to reproduce it, and allow
time for a fix before disclosing it publicly.

## If you send a pull request anyway

It will be closed with a link to this file. Nothing is being read into it — the
policy above applies to every pull request regardless of its content.

---

## 日本語

**本プロジェクトは、外部からのコードの寄稿（プルリクエスト）を一切受け付けていません。**
送られた PR はマージせずクローズします。一方で、**不具合報告・要望は歓迎します**。
[Issues](../../issues) へお寄せください。

理由は、AGPLv3 と商用ライセンスのデュアルライセンスで提供しているためです。他者の
コードが数行でも入ると、その部分の著作権は寄稿者に帰属し、そのコードを含む商用
ライセンスを販売するには寄稿者全員の許諾または権利譲渡契約（CLA）が必要になります。
著作権を作者が単独で保有し続けることで、この問題を管理するのではなく回避しています。

**サポートの約束はありません。** 本プロジェクトは本業のかたわら 1 人で維持しており、
応答時間や修正時期をお約束できません。報告いただいた不具合は、作者自身が修正を書いて
品質向上に活用します。

**脆弱性は公開 Issue ではなく <support@2bo-velocity.com> へご連絡ください。**
