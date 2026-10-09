# Granular Git Commit Plan

> Contoh urutan; sesuaikan dengan perubahan nyata. Jangan membuat commit yang menyatakan pekerjaan selesai jika belum.

1. `docs: add project requirements and scope`
2. `docs: define initial architecture and domain rules`
3. `chore: bootstrap Laravel application`
4. `chore: add environment example and ignore local secrets`
5. `db: add institutions schema`
6. `db: add tenant-scoped invoice schema`
7. `db: add payment transaction schema`
8. `db: add webhook audit receipt schema`
9. `db: seed three demo institutions`
10. `test: cover webhook signature verification`
11. `feat: add invoice creation validation`
12. `feat: create invoices within tenant context`
13. `test: cover invoice tenant isolation`
14. `feat: expose invoice status endpoint`
15. `feat: expose student invoice history`
16. `feat: expose invoice transaction history`
17. `feat: record incoming webhook receipts`
18. `feat: verify institution webhook signatures`
19. `feat: process successful payment events idempotently`
20. `feat: process expired payment events`
21. `test: cover duplicate and out-of-order webhooks`
22. `docs: document API and webhook contracts`
23. `docs: record technical decisions and AI pairing notes`
24. `test: verify core workflows and tenant boundaries`
25. `docs: finalize setup and verification instructions`

## Praktik
- Satu commit sebaiknya mewakili satu perubahan konseptual.
- Jangan membuat commit raksasa berisi semua fitur.
- Jalankan test relevan sebelum commit.
- Jangan commit `.env`, secret, database dump berisi data nyata, atau log sensitif.
- Pesan commit harus mencerminkan perubahan aktual, bukan menyalin daftar ini secara membabi buta.
