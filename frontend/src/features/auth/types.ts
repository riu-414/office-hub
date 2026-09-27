export type Department = {
  id: number
  name: string
}

export type UserSystem = {
  key: string
  name: string
  role: string
}

export type User = {
  id: number
  name: string
  email: string
  department: Department | null
  is_system_admin: boolean
  systems: UserSystem[]
}

export type LoginInput = {
  email: string
  password: string
}