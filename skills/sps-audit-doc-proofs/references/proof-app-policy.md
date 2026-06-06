# Proof App Policy

The proof app is a versioned test asset, not a disposable build artifact.

Use:

- source location: `tests/doc-proofs/tutorial-mainline-app/`
- generated runtime residue: `build/`

Do not introduce alternate proof-app entrypoints under `build/`.
The authoritative learning-mainline proof app lives only at `tests/doc-proofs/tutorial-mainline-app/`.
