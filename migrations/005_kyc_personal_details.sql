-- Round 5, Priority 3: professional KYC page — personal-details fields.
-- These live on `users` (one identity per user) rather than duplicated
-- across kyc_documents rows (which are per-document-type: pan/aadhaar/bank/selfie).
ALTER TABLE users
    ADD COLUMN kyc_full_name VARCHAR(100) DEFAULT NULL AFTER password_hash,
    ADD COLUMN kyc_dob DATE DEFAULT NULL AFTER kyc_full_name,
    ADD COLUMN kyc_address VARCHAR(255) DEFAULT NULL AFTER kyc_dob,
    ADD COLUMN kyc_city VARCHAR(100) DEFAULT NULL AFTER kyc_address,
    ADD COLUMN kyc_state VARCHAR(100) DEFAULT NULL AFTER kyc_city,
    ADD COLUMN kyc_pincode VARCHAR(10) DEFAULT NULL AFTER kyc_state;
