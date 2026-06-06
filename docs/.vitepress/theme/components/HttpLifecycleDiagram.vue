<template>
  <div class="switon-home-http-flow" aria-label="HTTP lifecycle flow">
    <div class="switon-home-http-flow-column">
      <div class="switon-home-http-flow-anchor">RequestReceived</div>
      <div class="switon-home-http-flow-label">RequestAdjust</div>
      <div class="switon-home-http-flow-label">RequestBegin</div>
      <div class="switon-home-http-flow-label">RequestAuthenticating / RequestAuthenticated</div>
      <div class="switon-home-http-flow-label">RequestRouting / RequestRouted</div>
      <div class="switon-home-http-flow-label">RequestAuthorizing / RequestAuthorized</div>
      <div class="switon-home-http-flow-label">RequestValidating / RequestValidated</div>
      <div class="switon-home-http-flow-label">RequestReady</div>
      <div class="switon-home-http-flow-label">RequestInvoking / ControllerResolved / RequestInvoked</div>
      <div class="switon-home-http-flow-label">RequestRendering / RequestRendered</div>
      <div class="switon-home-http-flow-label">ResponseAdjust</div>
      <div class="switon-home-http-flow-label">HeadersSending / HeadersSent</div>
      <div class="switon-home-http-flow-label">BodySending / BodySent</div>
      <div class="switon-home-http-flow-label">RequestEnd</div>
    </div>

    <div class="switon-home-http-flow-column switon-home-http-flow-column-exception">
      <div class="switon-home-http-flow-anchor switon-home-http-flow-anchor-exception">ExceptionDispatcher</div>
      <div class="switon-home-http-flow-label">RequestFailed</div>
      <div class="switon-home-http-flow-label">ResponseAdjust</div>
    </div>
  </div>
</template>
