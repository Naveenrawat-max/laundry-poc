/** Starts the web app: the theme, the login, and the pages of each role. */
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router'

import { Shell } from '@/app/shell'
import { ThemeProvider } from '@/components/theme-provider'
import { Spinner } from '@/components/ui/spinner'
import { ToastProvider } from '@/components/ui/toast'
import { appBase } from '@/lib/base'
import { SessionProvider, useSession } from '@/lib/session'
import { ContractsPage } from '@/pages/contracts'
import { CustomersPage } from '@/pages/customers'
import { DashboardPage } from '@/pages/dashboard'
import { HandheldPage, OwnHandheldPage } from '@/pages/handheld'
import { HandoversPage } from '@/pages/handovers'
import { LoginPage } from '@/pages/login'
import { MatsPage } from '@/pages/mats'
import { OrdersPage } from '@/pages/orders'
import { RegistrationPage } from '@/pages/register'
import { SetupPage } from '@/pages/setup'
import { TasksPage } from '@/pages/tasks'
import { UsersPage } from '@/pages/users'
import { WorkflowPage } from '@/pages/workflow'

function App() {
  const { user, ready } = useSession()
  if (!ready) {
    return (
      <div className="grid min-h-dvh place-items-center">
        <Spinner />
      </div>
    )
  }
  if (!user) return <LoginPage />
  // Only the office has a console. Every other role gets the screens of its handheld.
  if (user.role !== 'office') return <OwnHandheldPage />

  return (
    <Routes>
      <Route element={<Shell />}>
        <Route path="/workflow" element={<WorkflowPage />} />
        <Route path="/dashboard" element={<DashboardPage />} />
        <Route path="/tasks" element={<TasksPage />} />
        <Route path="/mats" element={<MatsPage />} />
        <Route path="/registration" element={<RegistrationPage />} />
        <Route path="/ready-to-use" element={<OrdersPage key="delivery" type="delivery" />} />
        <Route path="/pickup-orders" element={<OrdersPage key="pickup" type="pickup" />} />
        <Route path="/handovers" element={<HandoversPage />} />
        <Route path="/customers" element={<CustomersPage />} />
        <Route path="/contracts" element={<ContractsPage />} />
        <Route path="/setup" element={<SetupPage />} />
        <Route path="/users" element={<UsersPage />} />
        <Route path="/handheld" element={<HandheldPage />} />
        <Route path="*" element={<Navigate to="/workflow" replace />} />
      </Route>
    </Routes>
  )
}

createRoot(document.getElementById('app')!).render(
  <StrictMode>
    <ThemeProvider>
      <ToastProvider>
        <SessionProvider>
          <BrowserRouter basename={appBase() || '/'}>
            <App />
          </BrowserRouter>
        </SessionProvider>
      </ToastProvider>
    </ThemeProvider>
  </StrictMode>,
)
