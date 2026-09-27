import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import { LoginPage } from './features/auth/LoginPage'
import { InventoryHomePage } from './features/inventory/InventoryHomePage'
import { PortalPage } from './features/portal/PortalPage'
import { GuestOnly } from './routes/GuestOnly'
import { RequireAuth } from './routes/RequireAuth'
import { AppLayout } from './shared/components/AppLayout'

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route element={<GuestOnly />}>
          <Route path="/login" element={<LoginPage />} />
        </Route>

        <Route element={<RequireAuth />}>
          <Route element={<AppLayout />}>
            <Route path="/" element={<PortalPage />} />
            <Route path='/inventory' element={<InventoryHomePage />} />
          </Route>
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App