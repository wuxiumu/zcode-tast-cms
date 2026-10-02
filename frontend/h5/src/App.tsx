import React, { type ReactElement } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { getToken } from './api'
import Login from './pages/Login'
import Layout from './pages/Layout'
import Home from './pages/Home'
import Hall from './pages/Hall'
import Material from './pages/Material'
import MyTasks from './pages/MyTasks'
import TaskDetail from './pages/TaskDetail'
import Rewards from './pages/Rewards'

class Boundary extends React.Component<{ children: ReactElement }, { err: Error | null }> {
  state = { err: null as Error | null }
  static getDerivedStateFromError(err: Error) {
    return { err }
  }
  render() {
    if (this.state.err) {
      return (
        <div style={{ padding: 20, whiteSpace: 'pre-wrap', color: '#cf1322' }}>
          页面渲染出错：{String(this.state.err.stack || this.state.err.message || this.state.err)}
        </div>
      )
    }
    return this.props.children
  }
}

function RequireAuth({ children }: { children: ReactElement }) {
  const loc = useLocation()
  return getToken() ? children : <Navigate to="/login" state={{ from: loc.pathname }} replace />
}

export default function App() {
  return (
    <Boundary>
      <Routes>
      <Route path="/login" element={<Login />} />
      <Route
        element={
          <RequireAuth>
            <Layout />
          </RequireAuth>
        }
      >
        <Route path="/" element={<Home />} />
        <Route path="/c/:id" element={<Hall />} />
        <Route path="/m/:id" element={<Material />} />
        <Route path="/tasks" element={<MyTasks />} />
        <Route path="/tasks/:id" element={<TaskDetail />} />
        <Route path="/rewards" element={<Rewards />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Boundary>
  )
}
