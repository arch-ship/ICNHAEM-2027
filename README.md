# ICNHAEM-2027 Official Conference Website

Website for **ICNHAEM-2027** — *International Conference on Nanomaterials, Hydrogen and Advanced Energy Materials and Devices* (**18–20 March 2027**, Banaras Hindu University, Varanasi, India).

Organised by the **Students of Prof. O. N. Srivastava & Onkar Nath Educational Society, Varanasi, India**.
Dedicated to **Padma Shri Prof. O. N. Srivastava (31.12.1942 – 24.04.2021)**.

## Pages Included

| File | Description |
| --- | --- |
| `index.html` | Home — BHU hero, countdown, scope & scientific graphic, 4 thematic tracks, Prof. O. N. Srivastava dedication, leadership, key dates & fee summary |
| `about.html` | Scope, dedication, thematic tracks, searchable Organizing (14) & Advisory (53) committees, and BHU venue |
| `abstract.php` | Abstract submission guidelines, `.docx` template download, and PHP/MySQL submission form |
| `registration.php` | Brochure registration fees (Faculty Rs. 5,000, Post Doc. Rs. 3,000, PhD Students Rs. 2,500) and online registration form |
| `contact.html` | Convener, Co-Convener, both Organizing Secretaries, mailto contact form, and Varanasi travel info |

## Deployment (Hostinger / PHP + MySQL)

1. Extract this ZIP directly into `public_html` (so `index.html` is at the root).
2. Create a MySQL database in Hostinger hPanel and import `schema.sql` via phpMyAdmin.
3. Update database credentials and committee e-mails in `config.php`.
4. Ensure `uploads/abstract/` and `uploads/registration/` are writable.
