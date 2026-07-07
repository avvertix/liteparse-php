//! Async-to-sync bridge.
//!
//! PHP FFI calls are synchronous, but `liteparse`'s API is built on tokio.
//! We own a single process-wide multi-thread runtime and `block_on` each
//! FFI entry point, mirroring the `liteparse-python` (PyO3) binding's
//! approach.

use std::future::Future;
use std::sync::OnceLock;
use tokio::runtime::Runtime;

static RUNTIME: OnceLock<Runtime> = OnceLock::new();

fn runtime() -> &'static Runtime {
    RUNTIME.get_or_init(|| {
        Runtime::new().expect("liteparse_php: failed to start tokio runtime")
    })
}

pub fn block_on<F: Future>(future: F) -> F::Output {
    runtime().block_on(future)
}
